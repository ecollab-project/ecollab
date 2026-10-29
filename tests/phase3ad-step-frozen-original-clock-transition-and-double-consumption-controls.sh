git worktree add --detach "$RUNNER_TEMP/phase3ac-original" e4999821b16f8d8085a8cde6c5b08de2fc824ae5
cp -a vendor "$RUNNER_TEMP/phase3ac-original/vendor"
cd "$RUNNER_TEMP/phase3ac-original"
composer dump-autoload --no-interaction
python3 -B -u tests/phase3aa-experiments.py
python3 -B tests/phase3aa-report.py "$PHASE3AA_EVIDENCE" "$GITHUB_WORKSPACE/phase3ad-raw/original-summary.json"
