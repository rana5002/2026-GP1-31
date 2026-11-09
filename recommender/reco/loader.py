"""
loader.py - reads the data from the MySQL database. Nothing else in the recommender touches the database.

What it does, in simple words:
  1. Finds the database login in the same .env file that the PHP site uses.
  2. Connects to MySQL and READS some tables (it never writes or changes anything).
  3. Gives the tables to engine.py (the recommender).
"""
import os
from datetime import date
from urllib.parse import quote_plus

import pandas as pd
from sqlalchemy import create_engine, text

# The tables and columns we read. These are the real names from the Ufuq database.
# Only these columns are read. Gender, age, names, emails, phones and passwords are never read.
TABLE_COLUMNS = {
    "user": ["UserID", "EducationalStatus"],
    "userstudy": ["UserID", "FieldOfStudy", "DegreeLevel"],
    "skill": ["SkillID", "SkillName"],
    "userskill": ["UserID", "SkillID"],
    "experience": ["ExperienceID", "ExperienceName"],
    "userexperience": ["UserID", "ExperienceID", "JobTitle", "StartDate", "EndDate"],
    "qualification": ["QualificationID", "QualificationName"],
    "userqualification": ["UserID", "QualificationID"],
    "opportunity": ["OpportunityID", "Title", "Description", "Type", "IndustrySector",
                    "ApplicationDeadLine", "ApplicationOpenDate", "Status", "ViewCount"],
    "opportunityskill": ["OpportunityID", "SkillID"],
    "opportunityexperience": ["OpportunityID", "ExperienceID"],
    "opportunityqualification": ["OpportunityID", "QualificationID"],
    "favorite": ["UserID", "OpportunityID", "DateAdded"],
    "review": ["UserID", "OpportunityID", "Rating"],
}

# These columns hold dates, so we tell Python to treat them as dates.
DATE_COLUMNS = ["StartDate", "EndDate", "ApplicationDeadLine", "ApplicationOpenDate", "DateAdded"]


class Dataset:
    """A box that holds all the tables. dataset["skill"] gives the skill table."""

    def __init__(self, tables):
        self.tables = tables

    def __getitem__(self, name):
        return self.tables[name]


def _clean(df):
    """Tidy one table: real dates, a number for ViewCount, and empty text instead of NULL."""
    df = df.copy()
    for col in DATE_COLUMNS:
        if col in df.columns:
            df[col] = pd.to_datetime(df[col], errors="coerce")
    if "ViewCount" in df.columns:
        df["ViewCount"] = pd.to_numeric(df["ViewCount"], errors="coerce").fillna(0).astype(int)
    for col in df.columns:
        if df[col].dtype == object:
            df[col] = df[col].fillna("")
    return df


def _read_env_file():
    """Reads the .env file. It looks in the recommender folder and in the 3 folders above it, and uses the first one it finds."""
    folder = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))   # the "recommender" folder
    for _ in range(4):
        path = os.path.join(folder, ".env")
        if os.path.isfile(path):
            values = {}
            for line in open(path, encoding="utf-8"):
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    key, _, value = line.partition("=")
                    values[key.strip()] = value.strip().strip('"').strip("'")
            return values
        folder = os.path.dirname(folder)
    return {}


def _setting(name, default):
    """Gets one setting. A real environment variable wins, then the .env file, then the default."""
    return os.environ.get(name) or _read_env_file().get(name) or default


def load_default():
    """Connects to the database, reads the tables and returns them as a Dataset.
    The login uses the same names and default values as the PHP file config/db.php."""
    url = "mysql+pymysql://{user}:{password}@{host}:{port}/{name}".format(
        user=quote_plus(_setting("DB_USER", "root")),
        password=quote_plus(_setting("DB_PASSWORD", "root")),
        host=_setting("DB_HOST", "localhost"),
        port=_setting("DB_PORT", "8889"),
        name=_setting("DB_NAME", "ufuq"),
    )
    engine = create_engine(url, pool_pre_ping=True)
    tables = {}
    with engine.connect() as connection:
        for name, columns in TABLE_COLUMNS.items():
            sql = "SELECT " + ", ".join("`" + c + "`" for c in columns) + " FROM `" + name + "`"
            tables[name] = _clean(pd.read_sql(text(sql), connection))
    engine.dispose()          # close the connection (Clever Cloud allows only a few at the same time)
    return Dataset(tables)


def today():
    """Today's date. The recommender uses it to know which opportunities are open."""
    return pd.Timestamp(date.today())