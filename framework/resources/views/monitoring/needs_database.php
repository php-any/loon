<p>监控仪表盘需要可查询的历史数据。请将 <code>monitoring.driver</code> 设为 <code>database</code>（环境变量 <code>LOOM_MONITORING_DRIVER=database</code>），执行 <code>migrate:up</code> 创建 <code>loom_monitoring_*</code> 表，并登记 <code>$builder-&gt;monitoring-&gt;authorize(...)</code>。</p>
<p class="muted">当前为 log 或 null 驱动时，观测数据仅经 Diary 写出，不提供此 UI。</p>
