# 8385 老平台退役切换清单

> 老平台 `http://localhost:8385`（NSSM 服务 `HGTStudio`，SYSTEM 权限）是遗留原型，**非商用**。
> 新商用平台（Laravel + 8500 微服务）功能已全量覆盖并验证通过，老平台按本清单退役。

---

## 一、退役前置验证（新平台必须全通）

- [ ] 选题（行业自动/数量语义化/选用去二创）✅
- [ ] 二创（重点方向/字数统计/元数据/操作引导）✅
- [ ] 配音频（男女声语速·音调·音量 + 自然口吻）✅
- [ ] 出片（真实 CosyVoice 配音，D 基调情绪起伏）✅
- [ ] 质检（违禁词标红 + 人工审核状态机）✅
- [ ] 模特素材管理（上传/预览/重传/删除）✅
- [ ] 封面素材库（上传/出片关联/删除）✅
- [ ] 批量外发（publish_records + 演示桩）✅
- [ ] 数据模块（录入/CSV 导入/PlatformAdapter）✅
- [ ] 数据复盘看板（KPI/分布/Top/趋势）✅
- [ ] 配额计量（出片真实扣减）✅
- [ ] 端到端真实出片验证通过（有声视频产出）✅

---

## 二、切换步骤

1. **公告/过渡**（建议 1–2 周并行期）
   - 老平台继续运行，引导日常使用切到新平台
   - 确认日常主入口已切到 `http://localhost:8080`（或云端域名）

2. **停服老平台**（本地 Windows，管理员 PowerShell）
   ```powershell
   sc.exe stop HGTStudio
   sc.exe config HGTStudio start= disabled   # 禁用开机自启，保留可恢复
   # 确认：http://localhost:8385 已不可访问
   ```

3. **停自动化推送**（老平台每日 03:00 git push 任务）
   ```powershell
   schtasks /delete /tn "HGTStudioAutoPush" /f
   # 源码仍在 GitHub szrstudio 仓库，随时可恢复
   ```

4. **数据归档**（如有需保留）
   - 老平台源码：`D:/heygem_data/gpt_sovits/`（保留，数字人管线仍被新平台复用）
   - 老平台产出：`output/audio`、`output/video`、`output/pkg`（按需备份到冷存储）

5. **最终确认**
   - [ ] 老平台 8385 不可访问
   - [ ] 新平台 8080 正常出片
   - [ ] 本机 8500 服务 `HGTCommercial8500` RUNNING（新平台渲染依赖）
   - [ ] 用户日常主入口为新平台

---

## 三、回滚预案

- 若新平台出现严重问题：`sc.exe config HGTStudio start= auto` + `sc.exe start HGTStudio` 立即可恢复老平台
- 老平台代码/服务未删除，仅禁用，回滚秒级

---

## 四、彻底清理（确认无回滚需求后，可选）

- 卸载 `HGTStudio` 服务：`sc.exe delete HGTStudio`
- 删除 `output/` 历史产物（大数据量）
- 老平台仓库 `szrstudio` 保留作数字人管线参考，不删
