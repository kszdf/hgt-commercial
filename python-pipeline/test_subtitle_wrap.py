# -*- coding: utf-8 -*-
"""C1 回归单测：字幕换行宽度必须按实际画布(720-80=640px)计算，防 1080/720 混淆再犯。

背景：2026-08-25 事故——换行按成品 1080 宽(1000px)算，finalize 实际在 HEYGEM 源尺寸
720 宽画布上画字 → 长行居中后左右超界 → 出片贴边/切边（QC 75 分 warn）。
修复：SUBTITLE_VIDEO_W=720 → 换行上限 640px。

跑法：D:\\heygem\\py310\\Scripts\\python.exe test_subtitle_wrap.py   （依赖 PIL）
"""
import os
import sys
import importlib.util

from PIL import Image, ImageDraw, ImageFont

HERE = os.path.dirname(os.path.abspath(__file__))
FONT = r"D:\heygem_data\gpt_sovits\fonts\simhei.ttf"


def load_mafd():
    # 屏蔽 qwen_tts（模块级 import，避免碰网络/依赖）
    class FakeTTS:
        def synth(self, *a, **k):
            raise RuntimeError('no network')
    sys.modules['qwen_tts'] = FakeTTS()
    spec = importlib.util.spec_from_file_location('mafd', os.path.join(HERE, "make_avatar_from_dialogue.py"))
    m = importlib.util.module_from_spec(spec)
    sys.modules['mafd'] = m
    spec.loader.exec_module(m)
    return m


m = load_mafd()
MAX_W = m.SUBTITLE_MAX_W  # 640


def max_line_width(wrapped, font):
    tmp = Image.new('RGB', (1, 1))
    draw = ImageDraw.Draw(tmp)
    worst = 0.0
    for line in wrapped.split('\n'):
        w = draw.textlength(line, font=font)
        worst = max(worst, w)
    return worst


def check(text, font, expect_lines=None, label=''):
    wrapped = m._wrap_display_by_width(text, [font], max_width=MAX_W)
    w = max_line_width(wrapped, font)
    n = wrapped.count('\n') + 1
    ok_w = w <= MAX_W + 0.5
    ok_n = (expect_lines is None) or (n <= expect_lines)
    print(f"[{'PASS' if ok_w and ok_n else 'FAIL'}] {label or text[:18]}... "
          f"行数={n} 最宽行={w:.1f}px (上限{MAX_W})")
    assert ok_w, f'超界: {w:.1f}px > {MAX_W}px'
    assert ok_n, f'行数过多: {n} > {expect_lines}'
    return wrapped


def main():
    assert m.SUBTITLE_VIDEO_W == 720, 'SUBTITLE_VIDEO_W 必须=720（实际画布），防 1080/720 混淆回归'
    assert MAX_W == 640, f'换行上限必须=640 (720-80)，当前 {MAX_W}'
    font = ImageFont.truetype(FONT, m.SUBTITLE_FONT_SIZE)
    print(f'画布宽={m.SUBTITLE_VIDEO_W}, 换行上限={MAX_W}px (720-80)')
    # 1) 典型长句（财务口播）
    check('很多财务总监看到这里应该脊背发凉，因为你们每天都在做相反的事情，老板把字签在审批单上，你把字签在财务凭证上。',
          font, expect_lines=4, label='典型长句')
    # 2) 极端长句（无标点一口气）
    check('这是一段没有任何标点符号一口气说出来的超长句子用来验证换行逻辑在最坏情况下也不会溢出屏幕边界',
          font, expect_lines=4, label='无标点长句')
    # 3) 数字/百分比混合
    check('夏海钧十八点五五亿的薪酬里，有多少对应真实的经营价值？有多少对应虚增的五千六百亿收入？',
          font, expect_lines=4, label='数字长句')
    # 4) 半角字符（英数）混合
    check('CPA证书中级职称在法庭上不会成为免责理由反而会成为检察官的证词ABC1234567890',
          font, expect_lines=4, label='英数混合')
    # 5) 短句不过度断行
    r = check('你的签名比你的忠诚更值钱。', font, expect_lines=2, label='短句')
    assert r.count('\n') == 0, '短句不应被换行'
    # 6) 已有换行符保留
    r = check('第一行内容在这里。\n第二行内容在那里。', font, expect_lines=2, label='已有换行')
    assert '\n' in r, '已有换行应保留'
    print('\nALL C1 TESTS PASSED ✅')


if __name__ == '__main__':
    main()
