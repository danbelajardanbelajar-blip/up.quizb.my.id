"""CPU/model smoke tests; use public OpenCV sample photos, never student images."""
import base64
import importlib.util
import os
from pathlib import Path
import unittest
import cv2
import numpy as np

spec=importlib.util.spec_from_file_location('face_verify',Path(__file__).parents[1]/'bin/face-verify.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
MODELS=Path(os.environ.get('QUIZB_FACE_MODELS',str(Path.home()/'.local/share/quizb-proctoring/models')))
FIXTURES=Path(os.environ['QUIZB_FACE_FIXTURES'])
def encoded(image):
    ok,raw=cv2.imencode('.jpg',image);assert ok
    return base64.b64encode(raw).decode()
class FaceRuntimeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.image=cv2.imread(str(FIXTURES/'lena.jpg'))
        assert cls.image is not None
    def test_same_face(self):
        image=encoded(self.image)
        result=module.check({'frame':image,'reference':image},MODELS)
        self.assertEqual(result['status'],'MATCH');self.assertGreater(result['cosine'],.99)
    def test_same_face_rotated_and_reencoded(self):
        matrix=cv2.getRotationMatrix2D((256,256),-8,1)
        rotated=cv2.warpAffine(self.image,matrix,(512,512),borderMode=cv2.BORDER_REFLECT)
        result=module.check({'frame':encoded(rotated),'reference':encoded(self.image)},MODELS)
        self.assertEqual(result['status'],'MATCH');self.assertGreater(abs(result['roll']),.12)
    def test_no_face_is_rejected(self):
        with self.assertRaisesRegex(ValueError,'NO_FACE'):
            module.check({'frame':encoded(np.zeros((480,640,3),np.uint8))},MODELS)
    def test_multiple_faces_are_rejected(self):
        crop=self.image[140:440,140:440]
        image=np.concatenate([crop,crop],axis=1)
        with self.assertRaisesRegex(ValueError,'MULTIPLE_FACES'):
            module.check({'frame':encoded(image)},MODELS)
    def test_invalid_bytes_are_rejected(self):
        with self.assertRaises(ValueError):module.check({'frame':'not-an-image'},MODELS)
    def test_invalid_reference_cannot_match(self):
        result=module.check({'frame':encoded(self.image),'reference':encoded(np.zeros((480,640,3),np.uint8))},MODELS)
        self.assertEqual(result['status'],'REFERENCE_INVALID')
    def test_different_face(self):
        image=cv2.imread(str(FIXTURES/'messi5.jpg'))
        detector=cv2.FaceDetectorYN.create(str(MODELS/'yunet.onnx'),'',(image.shape[1],image.shape[0]),.8,.3,5000)
        _,faces=detector.detect(image);self.assertIsNotNone(faces)
        x,y,w,h=faces[0,:4].astype(int)
        crop=image[max(0,y-h//2):min(image.shape[0],y+h+h//2),max(0,x-w//2):min(image.shape[1],x+w+w//2)]
        crop=cv2.resize(crop,(320,320))
        result=module.check({'frame':encoded(crop),'reference':encoded(self.image)},MODELS)
        self.assertEqual(result['status'],'RECHECK')
if __name__=='__main__':unittest.main(verbosity=2)
