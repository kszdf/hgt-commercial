import sys, os
sys.path.insert(0, r'D:/heygem_data/hgt-commercial/python-pipeline')
sys.path.insert(0, r'D:/heygem_data/gpt_sovits')
os.chdir(r'D:/heygem_data/hgt-commercial/python-pipeline')

from model_providers import ensure_env
ensure_env()

from server import ai_dissect, ai_transcribe

sample = (
    "老板们注意了，虚开发票这个坑千万别踩。上周有个客户被查，补税加罚款三十多万。"
    "其实呢，只要你业务真实，三流一致，就不用担心。今天就跟大家聊聊怎么合规处理。"
    "记住，合同、资金、发票要对得上。有问题的评论区告诉我。"
)

print("=== ai_dissect (paste path) ===")
r = ai_dissect(sample, platform="抖音", industry="财税")
print("ok:", r.get("ok"))
print("mode:", r.get("mode"))
print("hook_type:", r.get("hook_type"))
print("pain_points:", (r.get("pain_points") or [])[:2])
print("case_evidence:", (r.get("case_evidence") or [])[:2])
print("emotion_rhythm len:", len(r.get("emotion_rhythm") or []))
print("structure len:", len(r.get("structure") or []))
print("reusable_parts:", (r.get("reusable_parts") or [])[:2])
print("must_replace:", (r.get("must_replace") or [])[:2])
print("rewrite_suggestions:", (r.get("rewrite_suggestions") or [])[:2])

# 校验 structure 字段完整性
bad = [s for s in (r.get("structure") or []) if not all(k in s for k in ("sec", "content", "emotion", "camera_hint"))]
print("structure field check:", "OK" if not bad else f"BAD {bad}")

# 校验 strategist 联动字段（控制器会再调 /strategist，这里仅确认 ai_dissect 不依赖它）
print("\n=== done ===")
