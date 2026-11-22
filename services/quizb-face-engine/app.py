"""Private API. Expose behind HTTPS reverse proxy; never expose model endpoints to browsers."""
import hmac
import json
import os
import time
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, Request
from fastapi.concurrency import run_in_threadpool
from engine import FaceEngine

TOKEN = os.environ.get('FACE_ENGINE_TOKEN', '')
if len(TOKEN) < 32:
    raise RuntimeError('Set FACE_ENGINE_TOKEN to a random secret of at least 32 characters')


@asynccontextmanager
async def lifespan(app):
    app.state.engine = FaceEngine()
    yield


app = FastAPI(lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)


@app.get('/health')
async def health(request: Request):
    return {'ready': hasattr(request.app.state, 'engine'), 'engine': 'opencv-sface-v1', 'capture_protocol': 'pose-burst-v2', 'max_frames': 9, 'motion_profile':'quizb-easy-v1', 'turn_min_ratio':0.08, 'return_max_ratio':0.14}


@app.post('/verify')
async def verify(request: Request):
    if not hmac.compare_digest(request.headers.get('authorization', ''), 'Bearer ' + TOKEN):
        raise HTTPException(401, 'Unauthorized')
    body = bytearray()
    async for chunk in request.stream():
        body.extend(chunk)
        if len(body) > 14_000_000:
            raise HTTPException(413, 'Payload too large')
    try:
        data = json.loads(body)
        if not isinstance(data, dict) or not isinstance(data.get('reference'), str):
            raise ValueError('Invalid reference')
        frames = data.get('frames')
        if not isinstance(frames, list) or len(frames) not in (1, 3, 9) or not all(isinstance(f, str) and len(f) <= 700000 for f in frames):
            raise ValueError('Invalid frames')
        started = time.perf_counter()
        result = await run_in_threadpool(request.app.state.engine.verify,
                                        data['reference'], frames, data.get('direction'))
        result['processing_ms'] = round((time.perf_counter() - started) * 1000)
        return result
    except (ValueError, TypeError, KeyError) as error:
        raise HTTPException(400, 'Invalid image request') from error
    except BlockingIOError as error:
        raise HTTPException(503, 'Engine busy; retry shortly') from error
