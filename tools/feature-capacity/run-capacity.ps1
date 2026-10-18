param(
  [Parameter(Mandatory=$true)][ValidateSet('voice','video','screen','code-run','code-grade','ai')][string]$Mode,
  [ValidateSet(2,4,50,100,150,200,250,300)][int]$Users=50,
  [ValidateSet('app-default','economy')][string]$MediaQuality='app-default',
  [ValidateRange(1,600)][int]$RampSeconds=120,
  [ValidateRange(10,1800)][int]$HoldSeconds=300,
  [ValidateRange(10,600)][int]$DeadlineSeconds=180,
  [ValidateRange(1,20)][int]$ShardCount=1,
  [ValidateRange(0,19)][int]$ShardIndex=0,
  [long]$StartAtMs=0
)
$ErrorActionPreference='Stop'
Set-Location $PSScriptRoot
$target=Get-Content .\feature-target.json -Raw | ConvertFrom-Json
$expected=if($Mode -in @('voice','video','screen')){'media'}else{$Mode}
if($target.mode -ne $expected){throw "Prepare a $expected target on the VPS and copy feature-target.json here first."}
$stamp=Get-Date -Format 'yyyyMMdd-HHmmss'
$report=Join-Path $PWD "results-$Mode-$Users-$stamp-shard-$ShardIndex.json"
$settings=@{TARGET_FILE=(Join-Path $PSScriptRoot 'feature-target.json');VUS="$Users";RAMP_SECONDS="$RampSeconds";HOLD_SECONDS="$HoldSeconds";DEADLINE_SECONDS="$DeadlineSeconds";REPORT_FILE=$report;MEDIA_PROFILE=$Mode;MEDIA_QUALITY=$MediaQuality;SHARD_COUNT="$ShardCount";SHARD_INDEX="$ShardIndex"}
if($StartAtMs -gt 0){$settings.START_AT_MS="$StartAtMs"}
$previous=@{}
foreach($key in $settings.Keys){$previous[$key]=[Environment]::GetEnvironmentVariable($key,'Process');[Environment]::SetEnvironmentVariable($key,$settings[$key],'Process')}
$exitCode=1
try{
  if($expected -eq 'media'){& node .\media-capacity.mjs}else{& k6 run .\api-capacity.js}
  $exitCode=$LASTEXITCODE
  if(Test-Path $report){
    $result=Get-Content $report -Raw | ConvertFrom-Json
    $result | Add-Member -Force -NotePropertyName process_exit_code -NotePropertyValue $exitCode
    if($exitCode -ne 0){$result.capacity_pass=$false}
    $json=$result | ConvertTo-Json -Depth 100
    [System.IO.File]::WriteAllText($report,$json,[System.Text.UTF8Encoding]::new($false))
  }
}finally{foreach($key in $settings.Keys){[Environment]::SetEnvironmentVariable($key,$previous[$key],'Process')}}
Write-Host "Exit code: $exitCode. Report: $report"
exit $exitCode
