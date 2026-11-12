"""
service.py - the small web service that PHP talks to.

How it works, in simple words:
  - We start it once (uvicorn reco.service:app --port 8000) and leave it running.
  - PHP sends it a request like  http://127.0.0.1:8000/recommend/5?k=5   (= "give me 5 recommendations for user 5").
  - It answers with a list: opportunity id, title and score. PHP then shows those opportunities.
"""
import time
import threading
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, Query

from .engine import Recommender
from .loader import load_default, today

# The data is read from the database again after this many seconds, so new students,
# new opportunities and new favorites are picked up without restarting the service.
REFRESH_SECONDS = 30

# What the service remembers: the recommender, when its data was read, and whether a refresh is running now.
_state = {"recommender": None, "loaded_at": 0.0, "refreshing": False}
_lock = threading.Lock()      # makes sure only one request changes the state at a time


def _load_now():
    """Reads the database and builds a new recommender. Returns True if it worked, False if it failed."""
    try:
        _state["recommender"] = Recommender(load_default(), today())
        _state["loaded_at"] = time.time()
        return True
    except Exception as error:
        print("Could not read the data:", error)
        return False


def _refresh_in_background():
    """Runs on the side. Students keep getting answers from the old data until the new data is ready."""
    if not _load_now():
        _state["loaded_at"] = time.time() - REFRESH_SECONDS + 60       # it failed, so try again in one minute
    _state["refreshing"] = False


def get_recommender():
    """Returns the recommender. Nobody waits for a refresh. Only the very first load makes the first request wait."""
    with _lock:
        if _state["recommender"] is None:
            if not _load_now():
                raise RuntimeError("The data could not be loaded yet")
        elif time.time() - _state["loaded_at"] > REFRESH_SECONDS and not _state["refreshing"]:
            _state["refreshing"] = True
            threading.Thread(target=_refresh_in_background, daemon=True).start()
        return _state["recommender"]


@asynccontextmanager
async def lifespan(app):
    """Runs once when the service starts: load the data now, so the first student does not have to wait."""
    try:
        get_recommender()
    except Exception as error:
        print("Could not load the data at start-up (the next request will try again):", error)
    yield


app = FastAPI(title="Ufuq recommender", lifespan=lifespan)


@app.get("/health")
def health():
    """A simple check that the service is running and has data. Open it in the browser to test."""
    recommender = get_recommender()
    return {"status": "ok", "opportunities": int(len(recommender.opp_ids))}


RELOAD_IF_UNKNOWN_SECONDS = 20

@app.get("/recommend/{user_id}")
def recommend(user_id: int, k: int = Query(5, ge=1, le=50)):
    recommender = get_recommender()

    # A student the service has not seen yet (just signed up): reload the data once.
    if user_id not in recommender.students:
        with _lock:
            if time.time() - _state["loaded_at"] > RELOAD_IF_UNKNOWN_SECONDS:
                _load_now()
            recommender = _state["recommender"]

    if user_id not in recommender.students:
        raise HTTPException(404, "unknown user")

    items = recommender.recommend(user_id, k=k)
    return {"user_id": user_id, "count": len(items), "recommendations": items}


@app.get("/reload")
def reload_data():
    with _lock:
        _load_now()
    return {"status": "reloaded"}

@app.get("/explain/{user_id}")
def explain(user_id: int, k: int = Query(20, ge=1, le=50)):
    recommender = get_recommender()
    if user_id not in recommender.students:
        raise HTTPException(404, "unknown user")
    return {"user_id": user_id, "explain": recommender.explain(user_id, k=k)}