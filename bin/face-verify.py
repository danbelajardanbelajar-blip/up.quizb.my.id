"""Local-only YuNet/SFace verification. One bounded JSON request on stdin; no network."""
import base64
import json
import math
import os
from pathlib import Path
import sys

# Set limits before importing NumPy/OpenCV; shared hosting may expose many CPUs.
os.environ['OPENBLAS_NUM_THREADS'] = '1'
os.environ['OMP_NUM_THREADS'] = '1'
os.environ['MKL_NUM_THREADS'] = '1'
os.environ['OPENCV_IO_MAX_IMAGE_PIXELS'] = '4000000'

MODEL_VERSION = 'yunet-2023mar-sface-2021dec-v1'

def check(payload, model_dir):
    import cv2
    import numpy as np
    cv2.setNumThreads(1)
    detector = cv2.FaceDetectorYN.create(str(model_dir / 'yunet.onnx'), '', (640, 480), 0.90, 0.3, 5000)
    recognizer = cv2.FaceRecognizerSF.create(str(model_dir / 'sface.onnx'), '')

    def face(encoded):
        raw = base64.b64decode(encoded, validate=True)
        if not 500 <= len(raw) <= 2_000_000:
            raise ValueError('INVALID_IMAGE')
        image = cv2.imdecode(np.frombuffer(raw, np.uint8), cv2.IMREAD_COLOR)
        if image is None or min(image.shape[:2]) < 160 or image.shape[0] * image.shape[1] > 4_000_000:
            raise ValueError('INVALID_IMAGE')
        scale = min(1.0, 640 / max(image.shape[:2]))
        if scale < 1:
            image = cv2.resize(image, None, fx=scale, fy=scale)
        detector.setInputSize((image.shape[1], image.shape[0]))
        _, faces = detector.detect(image)
        if faces is None or len(faces) == 0:
            raise ValueError('NO_FACE')
        if len(faces) != 1:
            raise ValueError('MULTIPLE_FACES')
        box = faces[0]
        if min(box[2:4]) < 60:
            raise ValueError('LOW_QUALITY')
        aligned = recognizer.alignCrop(image, box)
        gray = cv2.cvtColor(aligned, cv2.COLOR_BGR2GRAY)
        if not 35 <= float(gray.mean()) <= 240 or float(cv2.Laplacian(gray, cv2.CV_64F).var()) < 12:
            raise ValueError('LOW_QUALITY')
        feature = recognizer.feature(aligned)
        if feature.size != 128 or not np.isfinite(feature).all() or float(np.linalg.norm(feature)) < 1e-6:
            raise ValueError('INVALID_FEATURE')
        # Eye landmarks measure roll, not a claim that presentation attacks are solved.
        roll = math.atan2(float(box[5] - box[7]), abs(float(box[4] - box[6])) + 1e-6)
        return feature, roll

    live, roll = face(payload['frame'])
    if not payload.get('reference'):
        return {'status': 'FACE_PRESENT', 'roll': roll, 'model': MODEL_VERSION}
    try:
        reference, _ = face(payload['reference'])
    except (ValueError, KeyError):
        return {'status': 'REFERENCE_INVALID', 'model': MODEL_VERSION}
    score = float(recognizer.match(reference, live, cv2.FaceRecognizerSF_FR_COSINE))
    if not math.isfinite(score):
        raise ValueError('INVALID_FEATURE')
    # Conservative operational threshold, not an identity probability or accuracy claim.
    return {'status': 'MATCH' if score >= 0.50 else 'RECHECK', 'cosine': round(score, 6), 'roll': roll, 'model': MODEL_VERSION}


def main():
    directory = Path(os.environ.get('QUIZB_FACE_MODELS', str(Path.home() / '.local/share/quizb-proctoring/models')))
    if '--health' in sys.argv:
        import cv2
        cv2.setNumThreads(1)
        assert (directory / 'yunet.onnx').is_file() and (directory / 'sface.onnx').is_file()
        cv2.FaceDetectorYN.create(str(directory / 'yunet.onnx'), '', (640,480))
        cv2.FaceRecognizerSF.create(str(directory / 'sface.onnx'), '')
        return {'status':'READY', 'model':MODEL_VERSION}
    raw = sys.stdin.buffer.read(6_000_001)
    if len(raw) > 6_000_000:
        raise ValueError('INVALID_IMAGE')
    return check(json.loads(raw), directory)

if __name__ == '__main__':
    try:
        print(json.dumps(main(), allow_nan=False))
    except (ValueError, KeyError, TypeError) as error:
        code = str(error)
        print(json.dumps({'status':code if code in {'NO_FACE','MULTIPLE_FACES','LOW_QUALITY','INVALID_IMAGE','INVALID_FEATURE'} else 'INVALID_IMAGE'}))
    except Exception:
        # Never leak image content, paths or private configuration to API output.
        print(json.dumps({'status':'UNAVAILABLE'}))
        sys.exit(1)
