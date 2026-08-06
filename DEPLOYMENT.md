# 慧根堂商用短视频平台 · 部署与运行手册

> 阶段：Phase 1–3（多租户 + 注册登录 + 真实配音出片 + 本地数字人出镜 + 计费配额）
> 定位：商用 SaaS 平台（全新重写，跑通后 8385 老原型退役）

## 1. 架构总览

```
浏览器 ──http://localhost:8080──▶ nginx(:8080)
                                      │ fastcgi
                                      ▼
                              app (php:8.4-fpm)  Laravel 11 + Livewire 4 + FluxUI 2 + Tailwind v4
                                      │  HTTP (host.docker.internal:8500)
                                      ▼
                            Python 出片微服务 (:8500, Windows 宿主)
                                      │  subprocess
                                      ▼
                       make_scroll_video.py  ──▶ 滚动字幕卡 mp4
                                      ▲
                          ffmpeg + PY310 + 音色密钥(model_keys.env)

          mysql:8.0 (hgt_commercial)  ·  redis:alpine
```

- **Web 工作台**：Laravel 管「人 / 租户 / 界面 / 计费」，提供登录、注册、工作台、视频出片页、计费订阅页。
- **容器编排**（`docker-compose.yml`）：`app`(php-fpm) / `nginx`(:8080) / `mysql:8.0` / `redis:alpine`。**四个服务均设 `restart: always`**（Docker 守护进程重启后自动拉起，避免云端 frp→本地 8080 断流导致 502）。
- **视频出片**：微服务支持两种模式——
  - `scroll`：滚动字幕卡（不出镜，男女双声，**真实 CosyVoice 配音**）。
  - `avatar`：本地数字人出镜（HEYGEM 嘴型对齐 + 真实配音 + 烧字幕拼片头）。
  - 因依赖 Windows 绝对路径 ffmpeg + 本地 `qwen_tts` + HEYGEM，**微服务必须跑在 Windows 宿主**（非 Linux 容器）；Laravel 经 `host.docker.internal:8500` 调用。

## 2. 环境要求

- Docker Desktop（已配置国内镜像 `daocloud` + `163`，见 `~/.docker/daemon.json`）。
- Windows 宿主：Python 3.10（`D:/heygem/py310/Scripts/python.exe`）+ ffmpeg（`D:/ffmpeg/...`）+ 既有音色密钥（`D:/heygem_data/gpt_sovits/model_keys.env`）。
- **真实配音依赖**：PY310 须安装 `dashscope`（`D:/heygem/py310/Scripts/pip.exe install dashscope`），否则真实 TTS 报 `ModuleNotFoundError`。`model_keys.env` 的 `DASHSCOPE_API_KEY` 由 `qwen_tts` 自动灌入环境变量。
- **本地数字人(avatar)依赖**：HEYGEM Docker 容器 `heygem-gen-video` 须处于 Up（`docker start heygem-gen-video`）。
- 端口：Web `8080`（宿主可访问）、Python 微服务 `8500`（仅宿主内 / 经 Docker 访问）。

## 3. 启动步骤

```powershell
# 1) 容器栈（构建 php:8.4 镜像 + 起 nginx/mysql/redis）
cd D:/heygem_data/hgt-commercial
docker compose up -d --build

# 2) 数据库迁移 + 种子（默认租户 huigentang / admin@huigentang.com / admin888）
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force

# 3) 启动 Python 出片微服务（Windows 宿主，由 NSSM 服务 `HGTCommercial8500` 常驻，开机自启）
#    查看状态：  sc query HGTCommercial8500
#    重启服务：  sc stop HGTCommercial8500  &&  sc start HGTCommercial8500
#    （改源码后须重启该服务才能生效：先 stop 再 start）
```

> ⚠️ **视频出片功能依赖宿主 Python 微服务常驻**。NSSM 服务 `HGTCommercial8500` 崩溃自启；若服务被手动停止或机器重启后未起，点「生成视频」会返回「出片服务暂不可用」，重启该服务即恢复。

浏览器打开 **http://localhost:8080**。

## 4. 默认账号

| 项 | 值 |
|----|----|
| 租户 | `huigentang`（慧根堂） |
| 管理员邮箱 | `admin@huigentang.com` |
| 密码 | `admin888` |

也可在 `/register` 自助开通新租户（自动建租户 + 管理员，登录后进入工作台）。

## 5. 视频出片（滚动字幕卡）闭环

1. 工作台 → 左侧「视频出片」→ 粘贴对话稿（每行以 `女：` / `男：` 开头）+ 标题/副标题。
2. 点「生成视频」→ Laravel 代理请求到宿主 Python 微服务 → `make_scroll_video.py` 渲染 → 轮询状态 → 页面内播放/下载 mp4。
3. **演示模式** `dry_tts=true`：跳过真实 TTS（静音占位），仅验证画面与时间轴，无需联网与密钥。
4. 接真实 CosyVoice 音色：将 `VideoController` 中 `dry_tts` 改为 `false`（需 `model_keys.env` 中 dashscope key）。

## 6. 开发 / 修改注意

- 容器挂载宿主项目目录（含 `vendor/`），`Dockerfile` **不 COPY 代码**；改 PHP 后 `docker compose up -d --build` 重建。
- 前端（Blade/CSS/JS）改动后需 `npm run build`（产物 `public/build/` 已挂载进容器）；本地联调可用 `npm run dev`。
- `vendor/` 由 `composer:latest`（PHP 8.4）装出，故运行镜像须 `php:8.4-fpm`（8.3 会触发 Composer platform_check 报错）。
- 构建上下文已用 `.dockerignore` 排除 `node_modules/vendor/public/build`，重建更快。

## 7. 后续路线

- Phase 2：其余管线（本地数字人 / 第三方 SaaS 出片）、真实 TTS 接入、长任务队列。
- Phase 3：计费订阅（套餐 / 配额 / 用量 / 发票）。
- Phase 4：上云部署，8385 老平台退役。

## 8. Phase 1 验证状态（2026-07-30 已跑通）

| 验证项 | 结果 | 说明 |
|--------|------|------|
| 容器栈 | ✅ | `docker compose ps`：app(php 8.4-fpm) / nginx(:8080) / mysql:8.0 / redis 全 Up |
| 多租户数据 | ✅ | `tenants` / `users` 表迁移完成；种子建 `huigentang` / `admin@huigentang.com` / `admin888` |
| 登录 | ✅ | `POST /login` → 302 → `GET /dashboard` 200（含租户名/退出/视频出片入口） |
| 注册 | ✅ | `POST /register` 自动建租户 + 管理员并登录；表 utf8mb4，中文租户名存储正确 |
| 滚动字幕卡出片闭环 | ✅ | 浏览器提交 → Laravel 代理 `host.docker.internal:8500` → `make_scroll_video.py --dry-tts` → 轮询 → 下载 mp4（实测 ~381KB 有效 mp4） |
| CSRF | ✅ | `app-layout` 已补 `<meta name="csrf-token">`，前端 `fetch` 带 `X-CSRF-TOKEN`，代理生成 200 不 419 |

**字符集说明**：表为 `utf8mb4`（实测 `慧根堂` 以 UTF-8 正确落库）。调试用 curl 若从 GBK 终端传入中文会被当作 GBK 字节而触发 MySQL `1366`，属测试工具假象；真实浏览器以 UTF-8 提交无此问题。

**关键修复（当日）**：① 路由补 `name('login')`/`name('dashboard')` 修复 `auth` 中间件 `route('login')` 未定义；② 登录表单由占位 `alert` 改为真实 `@csrf` + `action=/login` 表单；③ `app-layout` 补 csrf-token meta 标签，否则出片 `fetch` 被 419 拦截。
