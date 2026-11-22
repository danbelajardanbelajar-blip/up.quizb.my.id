"""Install a private CPU runtime and checksum-pinned official OpenCV Zoo models."""
import hashlib
import os
from pathlib import Path
import subprocess
import sys
import urllib.request
import venv

ROOT = Path(os.environ.get('QUIZB_FACE_RUNTIME', str(Path.home() / '.local/share/quizb-proctoring')))
SHA = '48b10fa505aeb51fbd127fc60a5c660040e823b7'
MODELS = [('face_detection_yunet/face_detection_yunet_2023mar.onnx','yunet.onnx','8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4'),('face_recognition_sface/face_recognition_sface_2021dec.onnx','sface.onnx','0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79')]
if sys.version_info < (3, 9):
    raise SystemExit('Python >=3.9 required')
ROOT.mkdir(parents=True, exist_ok=True)
venv.create(ROOT/'venv', with_pip=True)
python = ROOT/'venv'/('Scripts/python.exe' if os.name=='nt' else 'bin/python')
subprocess.run([str(python),'-m','pip','install','opencv-python-headless==4.13.0.92'],check=True)
models = ROOT/'models';models.mkdir(exist_ok=True)
for source,name,digest in MODELS:
    destination=models/name
    if not destination.exists() or hashlib.sha256(destination.read_bytes()).hexdigest()!=digest:
        request=urllib.request.Request(f'https://media.githubusercontent.com/media/opencv/opencv_zoo/{SHA}/models/{source}',headers={'User-Agent':'QuizB-proctoring-setup'})
        with urllib.request.urlopen(request,timeout=120) as response:
            blob=response.read(45_000_000)
        if hashlib.sha256(blob).hexdigest()!=digest:
            raise SystemExit('Model checksum mismatch: '+name)
        temporary=destination.with_suffix('.download');temporary.write_bytes(blob);temporary.replace(destination)
    license_source=source.rsplit('/',1)[0]+'/LICENSE'
    with urllib.request.urlopen(f'https://raw.githubusercontent.com/opencv/opencv_zoo/{SHA}/models/{license_source}',timeout=30) as response:
        (models/(name+'.LICENSE')).write_bytes(response.read())
print('Runtime:', python)
subprocess.run([str(python),str(Path(__file__).with_name('face-verify.py')),'--health'],check=True,env={**os.environ,'QUIZB_FACE_MODELS':str(models)})
