"""
evaluate.py - the hide-one test.

For every saved favorite (of a student who has at least 2 favorites):
  1. hide that favorite (it is removed from the favorites table BEFORE the scores are built, so nothing leaks),
  2. ask the recommender for a ranking,
  3. check whether the hidden favorite is in the top 1 / 3 / 5.
Two baselines are measured on the same cases: "random" (expected value, calculated exactly)
and "popular" (the most popular opportunities).

The test runs on two groups:
  - the real accounts in the database,
  - 12 SIMULATED students, made in memory only (nothing is written to the database).

Run:   cd recommender
       python evaluate.py
Details of every case are saved in evaluation_results.csv.
"""
import random

import numpy as np
import pandas as pd

from reco.engine import Recommender
from reco.loader import load_default, today

KS = (1, 3, 5)              # hit rate at 1, at 3 and at 5
MIN_FAVORITES = 2           # the student needs at least one favorite left after hiding one
SEED = 2026                 # fixed, so the simulated students are the same every run
SIM_FIRST_ID = 900001

# Simulated tastes. "opps" = opportunity ids tagged with this theme (from their titles).
THEMES = {
    "ai": {"opps": [43, 50, 29, 42], "field": "Artificial Intelligence",
           "skills": ["Python", "Machine Learning", "Deep Learning", "NLP", "Generative AI",
                      "Large Language Models", "Prompt Engineering"]},
    "data": {"opps": [31, 37, 46], "field": "Data Science",
             "skills": ["Python", "SQL", "Data Analysis", "Data Visualization", "Power BI", "Tableau", "Statistics"]},
    "cyber": {"opps": [32, 38, 28], "field": "Cybersecurity",
              "skills": ["Linux", "Kali Linux", "Network Security", "Information Security",
                         "Cybersecurity Fundamentals", "Wireshark", "Ethical Hacking"]},
    "cloud": {"opps": [47, 27], "field": "Information Technology",
              "skills": ["AWS", "Cloud Computing", "Cloud Architecture", "Linux", "Cloud Basics"]},
    "business": {"opps": [30, 48, 49], "field": "Information Systems",
                 "skills": ["Microsoft Excel", "Microsoft Word", "Analytical Thinking",
                            "Attention to Detail", "Presentation Skills", "Documentation"]},
}
PERSONAS = ["ai", "ai", "ai", "data", "data", "data", "cyber", "cyber",
            "cloud", "business", "business", "ai+data"]


class DataView:
    """The same data as loader.py gives, but with some tables replaced."""

    def __init__(self, data, **replaced):
        self.data = data
        self.replaced = replaced

    def __getitem__(self, name):
        return self.replaced[name] if name in self.replaced else self.data[name]


def make_simulated(data):
    """Adds the simulated students to the data (in memory only). Returns the new data and the simulated ids."""
    rng = random.Random(SEED)
    now = pd.Timestamp(today())
    skill_id = {str(n).lower(): int(i) for n, i in zip(data["skill"].SkillName, data["skill"].SkillID)}
    known = sorted(int(o) for o in data["opportunity"].OpportunityID)

    users, skills, studies, favorites = [], [], [], []
    for number, persona in enumerate(PERSONAS):
        uid = SIM_FIRST_ID + number
        themes = persona.split("+")
        users.append(uid)

        chosen = []
        for theme in themes:
            pool = [o for o in THEMES[theme]["opps"] if o in known]
            take = 2 if len(themes) > 1 else min(3, len(pool))
            chosen += rng.sample(pool, min(take, len(pool)))
            for name in rng.sample(THEMES[theme]["skills"], min(4, len(THEMES[theme]["skills"]))):
                if name.lower() in skill_id:
                    skills.append({"UserID": uid, "SkillID": skill_id[name.lower()]})

        outside = [o for o in known if o not in {x for t in themes for x in THEMES[t]["opps"]}]
        chosen.append(rng.choice(outside))                     # one favorite outside the main interest

        studies.append({"UserID": uid, "FieldOfStudy": THEMES[themes[0]]["field"], "DegreeLevel": "Bachelor's Degree"})
        for oid in chosen:
            favorites.append({"UserID": uid, "OpportunityID": oid, "DateAdded": now - pd.Timedelta(days=rng.randint(1, 60))})

    view = DataView(
        data,
        user=pd.concat([data["user"], pd.DataFrame({"UserID": users})], ignore_index=True),
        userskill=pd.concat([data["userskill"], pd.DataFrame(skills)], ignore_index=True),
        userstudy=pd.concat([data["userstudy"], pd.DataFrame(studies)], ignore_index=True),
        favorite=pd.concat([data["favorite"], pd.DataFrame(favorites)], ignore_index=True),
    )
    return view, set(users)


def run_test(base, label, only_users=None):
    """The hide-one test on one data set. only_users = test only the favorites of these students."""
    rec = Recommender(base, today())
    all_fav = base["favorite"].reset_index(drop=True)
    per_student = all_fav.groupby("UserID").size()
    active = np.isin(rec.state, ["Open", "Upcoming"])      # only these can ever be recommended

    rows = []
    skipped_few = skipped_not_active = 0

    for fav in all_fav.itertuples():
        uid, oid = int(fav.UserID), int(fav.OpportunityID)
        if only_users is not None and uid not in only_users:
            continue
        if per_student[fav.UserID] < MIN_FAVORITES:
            skipped_few += 1
            continue
        if oid not in rec.opp_row or not active[rec.opp_row[oid]]:
            skipped_not_active += 1          # a closed opportunity can never be recommended, so it cannot be tested
            continue

        # Hide this one favorite: build the favorites and popularity again WITHOUT it.
        rest = all_fav.drop(index=fav.Index)
        test_data = DataView(base, favorite=rest)
        rec._prepare_favorites(test_data)
        rec._prepare_popularity(test_data)

        saved = {int(o) for u, o in zip(rest.UserID, rest.OpportunityID) if int(u) == uid}
        candidates = [i for i, o in enumerate(rec.opp_ids) if active[i] and int(o) not in saved]
        n = len(candidates)

        # 1) our recommender: the whole ranking, then the position of the hidden favorite
        ranking = [r["opportunity_id"] for r in rec.recommend(uid, k=n)]
        rank_system = ranking.index(oid) + 1 if oid in ranking else n

        # 2) popular baseline: the most popular first (ties keep the order of the opportunity id)
        by_popularity = sorted(candidates, key=lambda i: -rec.popularity[i])
        rank_popular = [int(rec.opp_ids[i]) for i in by_popularity].index(oid) + 1

        row = {"group": label, "student": uid, "hidden": rec.opp.Title.iloc[rec.opp_row[oid]],
               "candidates": n, "rank_system": rank_system, "rank_popular": rank_popular}
        for k in KS:
            row[f"system@{k}"] = int(rank_system <= k)
            row[f"popular@{k}"] = int(rank_popular <= k)
            row[f"random@{k}"] = min(k, n) / n          # chance of a random pick containing it
        rows.append(row)

    print(f"\n=== {label} ===")
    if not rows:
        print("No test cases.")
        return pd.DataFrame()

    results = pd.DataFrame(rows)
    print(f"Test cases: {len(results)}  (students: {results.student.nunique()}, "
          f"average candidates per case: {results.candidates.mean():.1f})")
    print(f"Skipped: {skipped_few} (fewer than {MIN_FAVORITES} favorites), "
          f"{skipped_not_active} (hidden opportunity is closed)\n")
    print(f"{'':10}{'Recommender':>14}{'Popular':>10}{'Random':>10}")
    for k in KS:
        print(f"hit@{k:<6}{results[f'system@{k}'].mean():>14.2f}{results[f'popular@{k}'].mean():>10.2f}"
              f"{results[f'random@{k}'].mean():>10.2f}")
    print(f"Average rank of the hidden favorite (lower is better): recommender {results.rank_system.mean():.1f}, "
          f"popular {results.rank_popular.mean():.1f}, random {(results.candidates.mean() + 1) / 2:.1f}")
    return results


def main():
    data = load_default()
    real = run_test(data, "Real accounts")

    simulated_data, simulated_ids = make_simulated(data)
    simulated = run_test(simulated_data, "Simulated students (12, made in memory)", only_users=simulated_ids)

    pd.concat([real, simulated], ignore_index=True).to_csv("evaluation_results.csv", index=False)
    print("\nDetails of every case: evaluation_results.csv")


if __name__ == "__main__":
    main()