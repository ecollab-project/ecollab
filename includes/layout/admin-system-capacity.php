<section class="page-section capacity-page" id="page-capacity" aria-labelledby="capacityTitle">
  <div class="capacity-heading"><div><h1 id="capacityTitle">System Capacity</h1><p>Compare live presence with recorded load-test evidence.</p></div><button class="btn-secondary" id="capacityRefresh" type="button">Refresh</button></div>
  <p id="capacityMessage" role="status" aria-live="polite">Loading capacity data…</p>
  <div class="capacity-stats">
    <article class="card"><span>Active users · last 2 minutes</span><strong id="capacityActive">—</strong><small>Unique users with recent presence or login activity</small></article>
    <article class="card"><span>Tested capacity reference</span><strong id="capacityTested">—</strong><small id="capacityWorkload">No evidence loaded</small></article>
    <article class="card"><span>Operational target</span><strong id="capacityTarget">—</strong><small>Admin planning target; does not block logins</small></article>
    <article class="card"><span>Today’s sampled peak</span><strong id="capacityPeak">—</strong><small id="capacityPeakAt">Asia/Manila · no samples yet</small></article>
  </div>
  <div class="card capacity-usage"><h2>Current usage</h2><div id="capacityUsageText">—</div><progress id="capacityUsage" max="100" value="0" aria-label="Usage relative to tested capacity"></progress><p id="capacityPresence">—</p><p class="capacity-note">Counts use existing presence timestamps, exclude synthetic k6 accounts, and count each user once. Presence is an estimate of recent users, not an exact count of open WebSocket connections. Capacity is specific to the selected test workload.</p></div>
  <div class="capacity-grid">
    <article class="card"><h2>Peak hours today <small>Asia/Manila</small></h2><div class="capacity-chart"><canvas id="capacityChart" role="img" aria-label="Hourly maximum of sampled active users"></canvas></div><p id="capacityCoverage" class="capacity-note">History starts when the collector is installed. Missing samples are gaps, not zero users.</p><div class="capacity-table-wrap"><table><caption>Hourly sampled peaks</caption><thead><tr><th>Hour</th><th>Peak users</th><th>Samples</th></tr></thead><tbody id="capacityHourly"></tbody></table></div></article>
    <article class="card"><h2>Plan for peak hours</h2><label for="capacityExpected">Expected simultaneous users</label><input id="capacityExpected" type="number" min="0" max="1000000" step="1" placeholder="e.g. 180"><p id="capacityForecast" role="status">Enter an expected user count to compare with the capacity reference.</p><p class="capacity-note">This comparison does not predict response times or performance with AI, calls, or document editing.</p>
      <form id="capacitySettings"><h2>Operational target</h2><label for="capacityTargetInput">Target users (optional)</label><input id="capacityTargetInput" name="operational_target" type="number" min="1" max="1000000" placeholder="Not set"><p class="capacity-note">Must be at or below the selected tested capacity. Leave blank to clear.</p><button class="btn-primary" type="submit">Save target</button></form>
    </article>
  </div>
  <article class="card"><h2>Add test evidence</h2><p class="capacity-note">Records are retained for review. Only a completed, passed test can become the capacity reference.</p>
    <form id="capacityRecord" class="capacity-form">
      <label>Tested concurrent users<input name="tested_users" type="number" min="1" max="1000000" required></label>
      <label>Test date<input name="tested_on" type="date" required></label>
      <label>Result<select name="result"><option value="passed">Passed</option><option value="failed">Failed</option><option value="untested">Unverified</option></select></label>
      <label>Planned hold (seconds)<input name="hold_seconds" type="number" min="1" max="86400" value="300" required></label>
      <label class="capacity-wide">Workload<input name="workload" maxlength="160" value="Chat reads, writes and realtime delivery" required></label>
      <label>API success (%)<input name="api_success_percent" type="number" min="0" max="100" step="any"></label>
      <label>HTTP p95 (ms)<input name="http_p95_ms" type="number" min="0" max="86400000" step="any"></label>
      <label>Own-message delivery p95 (ms)<input name="delivery_p95_ms" type="number" min="0" max="86400000" step="any"></label>
      <label class="capacity-wide">Evidence and limitations<textarea name="notes" maxlength="5000" rows="3" placeholder="Test duration, thresholds, result filename, failures and excluded features" required></textarea></label>
      <label class="capacity-checkbox"><input type="checkbox" name="completed"> Full test schedule completed</label>
      <label class="capacity-checkbox"><input type="checkbox" name="set_baseline"> Use as capacity reference</label>
      <div class="capacity-wide"><button class="btn-primary" type="submit">Save test record</button></div>
    </form>
  </article>
  <article class="card"><h2>Recorded tests</h2><div class="capacity-table-wrap"><table><thead><tr><th>Date</th><th>Users</th><th>Result</th><th>HTTP p95</th><th>Delivery p95</th><th>Evidence</th></tr></thead><tbody id="capacityRecords"></tbody></table></div></article>
  <article class="card"><h2>Settings history</h2><div class="capacity-table-wrap"><table><thead><tr><th>Recorded time (DB)</th><th>Admin ID</th><th>Test reference</th><th>Operational target</th></tr></thead><tbody id="capacityAudit"></tbody></table></div></article>
</section>
