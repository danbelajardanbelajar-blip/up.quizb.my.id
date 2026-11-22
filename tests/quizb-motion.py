"""Pure geometry tests: easier movement, unchanged identity threshold, no participant images."""
import ast
from pathlib import Path
import statistics
import unittest
source=Path(__file__).parents[1]/'services/quizb-face-engine/engine.py'
module=ast.parse(source.read_text(encoding='utf-8'))
functions=[node for node in module.body if isinstance(node,(ast.ClassDef,ast.FunctionDef)) and node.name in ('FaceError','motion_details')]
namespace={'statistics':statistics}
exec(compile(ast.Module(body=functions,type_ignores=[]),str(source),'exec'),namespace)
def face(ratio):
    f=[0.0]*15;f[4]=100;f[6]=200;f[8]=150+100*ratio;return f
def groups(turn,returned=0):return [[face(0)]*3,[face(turn)]*3,[face(returned)]*3]
class Motion(unittest.TestCase):
    def test_small_left_turn(self):self.assertTrue(namespace['motion_details'](groups(-.09),'left')[0])
    def test_small_right_turn(self):self.assertTrue(namespace['motion_details'](groups(.09),'right')[0])
    def test_wrong_direction(self):self.assertFalse(namespace['motion_details'](groups(.09),'left')[0])
    def test_no_motion(self):self.assertFalse(namespace['motion_details'](groups(0),'right')[0])
    def test_tiny_motion(self):self.assertFalse(namespace['motion_details'](groups(.03),'right')[0])
    def test_return_tolerance(self):self.assertTrue(namespace['motion_details'](groups(.09,.12),'right')[0])
    def test_not_returned(self):self.assertFalse(namespace['motion_details'](groups(.20,.20),'right')[0])
    def test_two_samples_required(self):
        g=groups(0);g[1][0]=face(.15);self.assertFalse(namespace['motion_details'](g,'right')[0])
    def test_identity_threshold_unchanged(self):self.assertIn("os.getenv('FACE_MATCH_THRESHOLD', '0.45')",source.read_text(encoding='utf-8'))
unittest.main()
