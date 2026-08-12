#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
HEYGEM 渲染后端看门狗 (watchdog)
============================================================
职责：
  1. 监控 heygem-gen-video（数字人渲染后端 :8383）与
     heygem-tts（配音 TTS :18180）两个容器的存活状态。
  2. 工作时段（07:00-22:00）内若发现容器退出，自动 docker start 拉起。
  3. 维护时段（22:00-07:00）内不拉起，尊重「每日 22:00 资源清理」任务
     释放显卡/内存的意图；次日 07:00 自动唤醒。

设计原则：稳、好用、易二次开发。
  - 仅用标准库，无第三方依赖（python 3.10+ 即可）。
  - 所有可调参数集中在下方 CONFIG 区，改这里即可。
  - 探活失败保守认为“活着”，避免误重启造成震荡。
  - 日志同时写文件与 stdout（NSSM 会把 stdout 重定向到日志）。
"""
import subprocess
import time
import datetime
import os
import sys

# 铁律：Windows 下 stdout 默认 GBK，重配置为 UTF-8，避免日志/输出乱码
try:
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")
except Exception:
    pass

# ======================= 可配置项（二次开发改这里） =======================
WATCH_TARGETS = {
    "heygem-gen-video": 8383,   # 数字人渲染后端（会被 22:00 清理任务 stop，需保活）
    "heygem-tts": 18180,        # 配音 TTS（当前在用；heygem-tts-old 为冗余遗留，不监控）
}
WORK_START_HOUR = 7             # 工作时段起点（含），到点自动唤醒
WORK_END_HOUR = 22              # 工作时段终点（不含）；22:00-07:00 为维护时段
POLL_INTERVAL = 60              # 探活间隔（秒）
# ==========================================================================

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOG_DIR = os.path.join(BASE_DIR, "runtime-logs")
LOG_FILE = os.path.join(LOG_DIR, "heygem_watchdog.log")


def log(msg):
    ts = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{ts}] {msg}"
    try:
        os.makedirs(LOG_DIR, exist_ok=True)
        with open(LOG_FILE, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except Exception:
        pass
    print(line, flush=True)


def is_running(name):
    """docker inspect 探活：容器进程是否在运行。"""
    try:
        r = subprocess.run(
            ["docker", "inspect", "-f", "{{.State.Running}}", name],
            capture_output=True, text=True, timeout=15,
        )
        return r.stdout.strip().lower() == "true"
    except Exception as e:
        log(f"探活 {name} 异常: {e}")
        return True  # 探活失败保守认为活着，避免误重启


def http_alive(port):
    """可选二次确认：端口能连通（非 000）即视为活着，404 也算连通。"""
    try:
        r = subprocess.run(
            ["curl", "-s", "-m", "4", "-o", "/dev/null", "-w", "%{http_code}",
             f"http://localhost:{port}/"],
            capture_output=True, text=True, timeout=10,
        )
        code = r.stdout.strip()
        return code != "" and code != "000"
    except Exception:
        return True  # 探活失败保守认为活着


def start_container(name):
    try:
        r = subprocess.run(["docker", "start", name], capture_output=True,
                           text=True, timeout=60)
        return r.returncode == 0
    except Exception as e:
        log(f"docker start {name} 异常: {e}")
        return False


def in_work_window():
    h = datetime.datetime.now().hour
    return WORK_START_HOUR <= h < WORK_END_HOUR


def main():
    log("=== HEYGEM 看门狗启动 ===")
    log(f"监控目标: {', '.join(WATCH_TARGETS.keys())} | "
        f"工作时段 {WORK_START_HOUR}:00-{WORK_END_HOUR}:00 | 探活间隔 {POLL_INTERVAL}s")
    while True:
        try:
            for name, port in WATCH_TARGETS.items():
                if is_running(name):
                    # 二次确认 HTTP（仅记录警告，不强制拉起，避免震荡）
                    if not http_alive(port):
                        log(f"[警告] {name} 容器在跑但 :{port} 无响应，下次探活复核")
                    continue
                # 容器不在运行
                if in_work_window():
                    ok = start_container(name)
                    log(f"{name} 未运行 -> 工作时段自动拉起: {'成功' if ok else '失败'}")
                else:
                    log(f"{name} 未运行 -> 维护时段跳过拉起（尊重22:00资源清理），"
                        f"{WORK_END_HOUR}:00后自动唤醒")
            time.sleep(POLL_INTERVAL)
        except Exception as e:
            log(f"主循环异常: {e}")
            time.sleep(POLL_INTERVAL)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        log("看门狗收到退出信号，停止")
        sys.exit(0)
