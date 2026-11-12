"""
engine.py - the recommender itself (content-based filtering).

The idea, in simple words:
  For ONE student, every open opportunity gets 6 small scores, each between 0 and 1:
      text          does the opportunity's text match the student's profile text? (TF-IDF + cosine similarity)
      skills        how many of the skills the opportunity asks for does the student have?
      experience    how many of the experience areas it asks for does the student have?
      qualification how many of the certificates it asks for does the student have?
      behavior      is it similar to the opportunities the student saved as favorites?
      quality       is it popular with everybody? (views, favorites, ratings) - small weight
  The 6 scores are multiplied by their weights (WEIGHTS below) and added. The best ones are recommended.

Nothing is trained and nothing is written to the database. This file only does calculations on the tables
that loader.py gives it.
"""
import re
from collections import defaultdict

import numpy as np
import pandas as pd
from scipy.sparse import csr_matrix, hstack
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.preprocessing import normalize

# How important each score is. They add up to 1. A bigger number means that score matters more.
WEIGHTS = {"text": 0.22, "skills": 0.32, "experience": 0.15, "qualification": 0.05,
           "behavior": 0.22, "quality": 0.04}

# The three kinds of "requirements" an opportunity can have and a student can have.
KINDS = ("skills", "experience", "qualification")

# A favorite loses half of its importance every 90 days (newer favorites tell more about what the student likes now).
HALF_LIFE_DAYS = 90

# Names like C++ or Node.js would be broken by the text cleaning, so we replace them with plain words first.
SPECIAL_NAMES = [("c++", "cpp"), ("c#", "csharp"), (".net", "dotnet"), ("node.js", "nodejs"), ("vue.js", "vuejs"),
                 ("next.js", "nextjs"), ("express.js", "expressjs"), ("ci/cd", "cicd"), ("ui/ux", "uiux"),
                 ("tcp/ip", "tcpip"), ("a+", "aplus"), ("network+", "networkplus"), ("security+", "securityplus"),
                 ("cysa+", "cysaplus"), ("linux+", "linuxplus"), ("cloud+", "cloudplus")]


def clean_text(text):
    """Makes text simple for matching: small letters, no punctuation."""
    text = str(text).lower()
    for old, new in SPECIAL_NAMES:
        text = text.replace(old, new)
    return re.sub(r"[^a-z0-9]+", " ", text).strip()


class Recommender:
    def __init__(self, data, today):
        """data = the tables from loader.py. today = today's date.
        Everything that is the same for all students is prepared here, once, so each request is fast."""
        self.today = pd.Timestamp(today)

        # Opportunities, in a fixed order. Row number i of every table below is the same opportunity.
        self.opp = data["opportunity"].sort_values("OpportunityID").reset_index(drop=True)
        self.opp_ids = self.opp["OpportunityID"].to_numpy()
        self.opp_row = {int(oid): row for row, oid in enumerate(self.opp_ids)}
        self.students = set(int(u) for u in data["user"].UserID)

        self._prepare_names_and_requirements(data)
        self._prepare_students(data)
        self._prepare_required_items_matrix()
        self._prepare_text(data)
        self._prepare_favorites(data)
        self._prepare_popularity(data)
        self._prepare_availability()

    # ------------------------------------------------------------------------------------------ preparing (once)
    def _prepare_names_and_requirements(self, data):
        """What each opportunity asks for (skills, experience areas, certificates)."""
        self.names = {
            "skills": dict(zip(data["skill"].SkillID, data["skill"].SkillName)),
            "experience": dict(zip(data["experience"].ExperienceID, data["experience"].ExperienceName)),
            "qualification": dict(zip(data["qualification"].QualificationID, data["qualification"].QualificationName)),
        }
        tables = {"skills": ("opportunityskill", "SkillID"),
                  "experience": ("opportunityexperience", "ExperienceID"),
                  "qualification": ("opportunityqualification", "QualificationID")}
        self.required = {kind: defaultdict(set) for kind in KINDS}       # required[kind][opportunity id] = set of ids
        for kind, (table, column) in tables.items():
            for oid, item in zip(data[table].OpportunityID, data[table][column]):
                self.required[kind][int(oid)].add(int(item))

    def _prepare_students(self, data):
        """What each student has: skills, experience, certificates, field of study, degree, job titles."""
        tables = {"skills": ("userskill", "SkillID"),
                  "experience": ("userexperience", "ExperienceID"),
                  "qualification": ("userqualification", "QualificationID")}
        self.has = {kind: defaultdict(set) for kind in KINDS}            # has[kind][student id] = set of ids
        for kind, (table, column) in tables.items():
            for uid, item in zip(data[table].UserID, data[table][column]):
                self.has[kind][int(uid)].add(int(item))

        # Longer experience counts more: 0.5 for a short one, up to 1.0 for a year or more.
        self.experience_strength = defaultdict(dict)
        self.job_titles = defaultdict(list)
        for row in data["userexperience"].itertuples():
            end = row.EndDate if pd.notna(row.EndDate) else self.today
            days = max(0, (end - row.StartDate).days) if pd.notna(row.StartDate) else 0
            strength = 0.5 + 0.5 * min(1.0, days / 365.0)
            old = self.experience_strength[int(row.UserID)].get(int(row.ExperienceID), 0)
            self.experience_strength[int(row.UserID)][int(row.ExperienceID)] = max(old, strength)
            if row.JobTitle:
                self.job_titles[int(row.UserID)].append(row.JobTitle)

        study = data["userstudy"]
        self.field = {int(u): f for u, f in zip(study.UserID, study.FieldOfStudy) if f}
        self.degree = {int(u): d for u, d in zip(study.UserID, study.DegreeLevel) if d}

    def _prepare_required_items_matrix(self):
        """A table: one row per opportunity, one column per skill (and the same for experience and certificates).
        A rare requirement (like Kubernetes) gets a bigger number than a common one (like Git), so it counts more."""
        n = len(self.opp)
        self.vocab, self.column_of, self.required_weight, self.required_total = {}, {}, {}, {}
        for kind in KINDS:
            ids = sorted(self.names[kind])
            self.vocab[kind] = ids
            self.column_of[kind] = {item: column for column, item in enumerate(ids)}
            rows, columns = [], []
            for oid, items in self.required[kind].items():
                for item in items:
                    if oid in self.opp_row and item in self.column_of[kind]:
                        rows.append(self.opp_row[oid])
                        columns.append(self.column_of[kind][item])
            ones = csr_matrix((np.ones(len(rows)), (rows, columns)), shape=(n, len(ids)))
            opportunities_asking = np.asarray(ones.sum(axis=0)).ravel()
            rarity = np.log((n + 1.0) / (opportunities_asking + 1.0)) + 1.0     # rare items get a bigger number
            weighted = ones.multiply(rarity).tocsr()
            self.required_weight[kind] = weighted
            self.required_total[kind] = np.asarray(weighted.sum(axis=1)).ravel()
        # All three kinds side by side. Used to compare opportunities with each other (for the favorites score).
        self.required_features = normalize(hstack([self.required_weight[k] for k in KINDS]).tocsr())

    def _prepare_text(self, data):
        """Turns the text of every opportunity into numbers (TF-IDF). The names of the required items are added to the text."""
        documents = []
        for row in self.opp.itertuples():
            oid = int(row.OpportunityID)
            asked = [self.names[k][x] for k in KINDS for x in sorted(self.required[k].get(oid, ()))]
            documents.append(clean_text(" ".join([row.Title, row.Title, row.Description, row.IndustrySector, row.Type] + asked)))
        self.tfidf = TfidfVectorizer(ngram_range=(1, 2), sublinear_tf=True, stop_words="english")
        self.opp_text = self.tfidf.fit_transform(documents)

    def _prepare_favorites(self, data):
        """The favorites of each student, as (opportunity row, importance). Newer favorites are more important."""
        self.favorites = defaultdict(list)
        for row in data["favorite"].itertuples():
            oid = int(row.OpportunityID)
            if oid in self.opp_row:
                age = max(0, (self.today - row.DateAdded).days) if pd.notna(row.DateAdded) else 0
                self.favorites[int(row.UserID)].append((self.opp_row[oid], 0.5 ** (age / HALF_LIFE_DAYS)))

    def _prepare_popularity(self, data):
        """One popularity number per opportunity, the same for every student: views, favorites and average rating."""
        count = len(self.opp)
        favorites_per_opp = data["favorite"].groupby("OpportunityID").size()
        ratings = data["review"].groupby("OpportunityID")["Rating"].agg(["sum", "count"])
        overall_average = data["review"]["Rating"].mean() if len(data["review"]) else 3.5

        views = np.log1p(self.opp["ViewCount"].to_numpy(float))
        saves = np.log1p(self.opp["OpportunityID"].map(favorites_per_opp).fillna(0).to_numpy(float))
        rating_sum = self.opp["OpportunityID"].map(ratings["sum"]).fillna(0).to_numpy(float) if count else np.zeros(0)
        rating_count = self.opp["OpportunityID"].map(ratings["count"]).fillna(0).to_numpy(float) if count else np.zeros(0)
        rating = (rating_sum + 3 * overall_average) / (rating_count + 3)   # with few reviews, it stays near the average

        rank = lambda values: pd.Series(values).rank(pct=True).to_numpy()   # turns numbers into 0..1 positions
        self.popularity = (rank(views) + rank(saves) + rank(rating)) / 3.0

    def _prepare_availability(self):
        """Open / Upcoming / Closed, with the same rule as browse.php. (Upcoming = applications open later.)"""
        deadline, opens = self.opp["ApplicationDeadLine"], self.opp["ApplicationOpenDate"]
        status_is_open = self.opp["Status"].str.strip().str.lower().isin(["open", "upcoming"])
        self.state = np.where(~status_is_open | (deadline < self.today), "Closed",
                              np.where(opens.notna() & (opens > self.today), "Upcoming", "Open"))

    # ------------------------------------------------------------------------------------------ one student
    def _student_text(self, uid):
        """The student's profile as one text: field of study (twice), degree, job titles, skill / experience / certificate names."""
        field = self.field.get(uid, "")
        parts = [field, field, self.degree.get(uid, "")] + self.job_titles.get(uid, [])
        for kind in KINDS:
            parts += [self.names[kind][x] for x in sorted(self.has[kind].get(uid, ())) if x in self.names[kind]]
        return clean_text(" ".join(parts))

    def _coverage(self, kind, uid):
        """For each opportunity: what share of the (weighted) requirements does the student have? 0 to 1.
        NaN (unknown) when the student has none of this kind, or the opportunity asks for nothing of this kind."""
        mine = [x for x in self.has[kind].get(uid, set()) if x in self.column_of[kind]]
        if not mine:
            return np.full(len(self.opp), np.nan)
        have = np.zeros(len(self.vocab[kind]))
        for item in mine:
            have[self.column_of[kind][item]] = self.experience_strength[uid].get(item, 1.0) if kind == "experience" else 1.0
        matched = self.required_weight[kind] @ have
        total = self.required_total[kind]
        share = np.full(len(self.opp), np.nan)
        asks = total > 0
        share[asks] = matched[asks] / total[asks]
        return share

    def _scores_of(self, uid):
        """The 6 small scores for every opportunity, as a table (rows = opportunities, columns = the 6 scores)."""
        count = len(self.opp)
        scores = {}

        text = self._student_text(uid)
        scores["text"] = np.full(count, np.nan)
        if text:
            similarity = np.asarray((self.opp_text @ self.tfidf.transform([text]).T).todense()).ravel()   # cosine similarity
            if similarity.max() > 0:
                scores["text"] = similarity

        for kind in KINDS:
            scores[kind] = self._coverage(kind, uid)

        scores["behavior"] = np.full(count, np.nan)
        saved = self.favorites.get(uid, [])
        if saved:
            rows = np.array([row for row, _ in saved])
            importance = np.array([w for _, w in saved])
            taste_text = normalize(csr_matrix(importance @ self.opp_text[rows].toarray()))
            taste_items = normalize(csr_matrix(importance @ self.required_features[rows].toarray()))
            by_text = np.asarray((self.opp_text @ taste_text.T).todense()).ravel()
            by_items = np.asarray((self.required_features @ taste_items.T).todense()).ravel()
            scores["behavior"] = 0.5 * by_text + 0.5 * by_items

        scores["quality"] = self.popularity
        return pd.DataFrame(scores, index=self.opp_ids)[list(WEIGHTS)]

    @staticmethod
    def _to_same_scale(scores):
        """Puts every score on a 0..1 scale: unknown values become the middle value, then each score is divided by its best value."""
        scaled = {}
        for name in scores.columns:
            values = scores[name].astype(float)
            middle = np.nanmedian(values.to_numpy()) if values.notna().any() else 0.0
            values = values.fillna(middle)
            best = values.max()
            scaled[name] = values / best if best > 0 else values * 0.0
        return pd.DataFrame(scaled, index=scores.index)



    def recommend(self, uid, k=5):
        """The best k opportunities for one student, best first. Only Open and Upcoming ones (never Closed),
        and never one the student already saved."""
        uid = int(uid)
        saved_ids = {self.opp_ids[row] for row, _ in self.favorites.get(uid, [])}
        allowed = np.isin(self.state, ["Open", "Upcoming"]) & ~np.isin(self.opp_ids, list(saved_ids))
        if not allowed.any():
            return []

        scores = self._scores_of(uid)[allowed]
        scaled = self._to_same_scale(scores)
        total = sum(WEIGHTS[name] * scaled[name] for name in WEIGHTS)         # the weighted sum
        best = total.sort_values(ascending=False, kind="mergesort").head(k)

        return [{"rank": rank, "opportunity_id": int(oid), "title": self.opp.Title.iloc[self.opp_row[int(oid)]],
                 "score": round(float(score), 4)} for rank, (oid, score) in enumerate(best.items(), 1)]


    def explain(self, uid, k=20):
        """Same as recommend(), but also shows the 6 scores of each opportunity (for checking and for the report).
        raw = the score before scaling (None = unknown). points = what it adds to the total."""
        uid = int(uid)
        saved_ids = {self.opp_ids[row] for row, _ in self.favorites.get(uid, [])}
        allowed = np.isin(self.state, ["Open", "Upcoming"]) & ~np.isin(self.opp_ids, list(saved_ids))
        if not allowed.any():
            return []

        scores = self._scores_of(uid)[allowed]
        scaled = self._to_same_scale(scores)
        total = sum(WEIGHTS[name] * scaled[name] for name in WEIGHTS)
        best = total.sort_values(ascending=False, kind="mergesort").head(k)

        rows = []
        for rank, (oid, score) in enumerate(best.items(), 1):
            rows.append({
                "rank": rank,
                "title": self.opp.Title.iloc[self.opp_row[int(oid)]],
                "total": round(float(score), 4),
                "raw": {name: (None if pd.isna(scores.loc[oid, name]) else round(float(scores.loc[oid, name]), 3))
                        for name in WEIGHTS},
                "points": {name: round(float(WEIGHTS[name] * scaled.loc[oid, name]), 3) for name in WEIGHTS},
            })
        return rows

        