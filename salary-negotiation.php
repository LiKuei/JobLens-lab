<?php
// ============ 面試談薪頁（實驗場：從 search.php 拆分） ============
$host = 'localhost';
$db_name = 'joblens';
$username = 'joblens';
$password = 'joblens';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// 參數：id = 公司，job = 職缺
$companyId = isset($_GET['id']) ? trim($_GET['id']) : '';
$jobId = isset($_GET['job']) ? trim($_GET['job']) : '';
if (empty($companyId) || empty($jobId)) {
    header("Location: not-found.html");
    exit;
}

// ---- 公司主查詢（同 search.php） ----
$stmt = $pdo->prepare("
    SELECT c.*, cc.Category, cc.Sector, cc.Subsector,
           s.NonAdminstrativeAverage, s.NonAdminstrativeMedian
    FROM company c
    LEFT JOIN salary s ON c.Id = s.CompanyId AND s.Year = 2024
    LEFT JOIN companycategory cc ON c.Id = cc.CompanyId
    WHERE c.Id = ? OR c.UniformId = ?
");
$stmt->execute([$companyId, $companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    header("Location: not-found.html");
    exit;
}

// ---- 目標職缺 ----
$stmt = $pdo->prepare("SELECT Id, Name, Url, Salary FROM recruitment WHERE Id = ? AND CompanyId = ?");
$stmt->execute([$jobId, $company['Id']]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

// ---- 該公司全部職缺（分紅結構用） ----
$stmt = $pdo->prepare("SELECT Name, Url, Salary FROM recruitment WHERE CompanyId = ?");
$stmt->execute([$company['Id']]);
$jobsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- 同 Sector 全部公司 2024 年中位數分布（談薪基準） ----
$stmt = $pdo->prepare("
    SELECT c.Name, c.Id, s.NonAdminstrativeMedian
    FROM company c
    JOIN salary s ON c.Id = s.CompanyId AND s.Year = 2024
    JOIN companycategory cc ON c.Id = cc.CompanyId
    WHERE cc.Sector = ?
      AND s.NonAdminstrativeMedian IS NOT NULL
    ORDER BY s.NonAdminstrativeMedian ASC
");
$stmt->execute([$company['Sector']]);
$sectorMedians = array_map(fn($r) => round($r['NonAdminstrativeMedian'] / 10000, 1), $stmt->fetchAll(PDO::FETCH_ASSOC));

// ---- 分紅依賴度：職缺月薪 vs 中位數等效月薪 ----
$jobSalaries = [];
$jobSalaryRanges = []; // [下限, 上限]
foreach ($jobsList as $j) {
    if (empty($j['Salary']) || !str_starts_with($j['Salary'], '月薪')) continue;
    if (preg_match_all('/\d[\d,]*/', $j['Salary'], $m)) {
        $nums = array_map(fn($s) => (int)str_replace(',', '', $s), $m[0]);
        $jobSalaries[] = count($nums) >= 2 ? ($nums[0] + $nums[1]) / 2 : $nums[0];
        $jobSalaryRanges[] = [min($nums), max($nums)];
    }
}
$salaryInsight = null;
if (count($jobSalaries) >= 3 && !empty($company['NonAdminstrativeMedian'])) {
    sort($jobSalaries);
    $insightCount = count($jobSalaries);
    $insightJobMed = $jobSalaries[intdiv($insightCount, 2)];
    $insightEquivMonthly = round($company['NonAdminstrativeMedian'] / 12); // 元/月
    $insightRatio = $insightEquivMonthly / $insightJobMed;
    $insightJobMin = min(array_column($jobSalaryRanges, 0));
    $insightJobMax = max(array_column($jobSalaryRanges, 1));
    $salaryInsight = compact('insightCount', 'insightJobMed', 'insightEquivMonthly', 'insightRatio', 'insightJobMin', 'insightJobMax');
}

// ---- 職位分類 ----
const JOB_CAT_RULES = [
    '資深/管理職' => ['資深', '高級', '主任', '經理', '副理', '課長', '協理', '總監', '處長', '店長', '組長', '幹部', '主管', '襄理', 'leader', 'coach', 'manager'],
    '工程師' => ['工程師', 'engineer'],
    'PM/專案' => ['pm', '專案', 'project'],
    '品管/檢驗' => ['品管', '品保', '檢驗', '量測', '品檢'],
    '技術員/作業員' => ['技術員', '作業員', '操作員', '組裝員', '領班', '技術人員', '技師', '技術師'],
    '門市/服務' => ['門市', '店員', '服務員', '服務生', '櫃檯', '櫃台', '餐飲', '廚師', '接待', '外場', '內場', '房務', '店務'],
    '業務/行銷' => ['業務', '行銷', 'sales', 'marketing'],
    '倉管/物流' => ['倉管', '倉儲', '理貨', '物料', '物流', '司機', '送貨', '堆高機', '倉庫', '運務'],
    '財務/會計' => ['會計', '財務', '財會', '出納', '審計', '記帳', '帳務'],
    '採購' => ['採購'],
    '人資' => ['人資', '人事', '招募', 'hr'],
    '醫療/護理' => ['護理', '藥師', '醫檢', '醫師', '照護', '治療', '醫事'],
    '助理/行政' => ['助理', 'assistant', 'secretary', '行政', '總務', '秘書'],
    '專員/管理師' => ['專員', '管理師', '顧問', '規劃師', '分析師', '職安', '職業安全', '環安', '安衛', '環保', '勞安', '法務', '律師'],
];

function classifyJob(string $name): string {
    $lower = mb_strtolower($name, 'UTF-8');
    foreach (JOB_CAT_RULES as $cat => $kws) {
        foreach ($kws as $kw) {
            if (str_contains($lower, mb_strtolower($kw, 'UTF-8'))) return $cat;
        }
    }
    return '其他';
}

$jobCat = $job ? classifyJob($job['Name']) : null;

// ---- 同產業該職位月薪帶（市場行情，樣本不足退回全台灣） ----
$marketCat = null; // [min, max, count, jobMin, jobMax, scope]
if ($jobCat && $jobCat !== '其他') {
    $marketScope = '同產業';
    $stmt = $pdo->prepare("
        SELECT r.Name, r.Salary, r.CompanyId FROM recruitment r
        JOIN companycategory cc ON cc.CompanyId = r.CompanyId
        WHERE cc.Sector = ? AND r.Salary LIKE '月薪%'
    ");
    $stmt->execute([$company['Sector']]);
    $sectorJobRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $catRanges = [];
    $companyCatRanges = [];
    foreach ($sectorJobRows as $sj) {
        if (classifyJob($sj['Name']) !== $jobCat) continue;
        if (preg_match_all('/\d[\d,]*/', $sj['Salary'], $m)) {
            $nums = array_map(fn($s) => (int)str_replace(',', '', $s), $m[0]);
            $range = [min($nums), max($nums)];
            $catRanges[] = $range;
            if ((int)$sj['CompanyId'] === (int)$company['Id']) $companyCatRanges[] = $range;
        }
    }
    if (count($catRanges) < 5) {
        // 同產業樣本不足 → 退回全台灣
        $stmt = $pdo->prepare("SELECT Name, Salary, CompanyId FROM recruitment WHERE Salary LIKE '月薪%'");
        $stmt->execute();
        $catRanges = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sj) {
            if (classifyJob($sj['Name']) !== $jobCat) continue;
            if (preg_match_all('/\d[\d,]*/', $sj['Salary'], $m)) {
                $nums = array_map(fn($s) => (int)str_replace(',', '', $s), $m[0]);
                $catRanges[] = [min($nums), max($nums)];
            }
        }
        $marketScope = '全台灣';
    }
    if (count($catRanges) >= 5) {
        $lows = array_column($catRanges, 0); sort($lows);
        $highs = array_column($catRanges, 1); sort($highs);
        $n = count($catRanges);
        $marketCat = [
            'min' => $lows[intdiv($n, 2)],
            'max' => $highs[intdiv($n, 2)],
            'count' => $n,
            'jobMin' => $companyCatRanges ? min(array_column($companyCatRanges, 0)) : null,
            'jobMax' => $companyCatRanges ? max(array_column($companyCatRanges, 1)) : null,
            'scope' => $marketScope,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>面試談薪 - <?= htmlspecialchars($company['Name'] ?? '') ?> | JobLens</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@300;400;500;700&display=swap');
        body { font-family: 'Noto Sans TC', sans-serif; background-color: #f8fafc; }
    </style>
</head>
<body class="text-slate-800">

    <nav class="bg-slate-900 text-white p-4 shadow-lg sticky top-0 z-50">
        <div class="container mx-auto flex justify-between items-center">
            <div class="flex items-center gap-3 cursor-pointer" onclick="window.location.href='index.php'">
                <img src="assets/magnifying-glass.png" alt="JobLens Logo" class="w-8 h-8 object-contain">
                <span class="text-xl font-bold tracking-wider">JobLens</span>
                <span class="text-xs bg-violet-500/20 text-violet-300 px-2 py-0.5 rounded-full">面試談薪</span>
            </div>
            <a href="search.php?id=<?= (int)$company['Id'] ?>" class="text-sm font-bold text-slate-300 hover:text-white transition flex items-center gap-2">
                <i class="fa-solid fa-arrow-left"></i> 返回 <?= htmlspecialchars($company['Name']) ?> 公司頁
            </a>
        </div>
    </nav>

    <main class="container mx-auto px-4 py-8 max-w-5xl">

        <?php if (!$job): ?>
        <div class="bg-white rounded-xl shadow-lg border border-slate-100 p-10 text-center">
            <p class="text-slate-500 font-bold text-lg">找不到這個職缺（可能已下架）</p>
            <a href="search.php?id=<?= (int)$company['Id'] ?>" class="inline-block mt-4 text-cyan-600 font-bold hover:underline">← 返回公司頁</a>
        </div>
        <?php else: ?>

        <!-- ① 職缺資訊卡 -->
        <div class="bg-white rounded-xl shadow-lg border border-slate-100 p-6 mb-6">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div class="flex items-start gap-3">
                    <div class="w-11 h-11 rounded-xl bg-violet-100 text-violet-600 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i class="fa-solid fa-briefcase text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-slate-800 leading-snug"><?= htmlspecialchars($job['Name']) ?></h1>
                        <p class="text-sm text-slate-500 mt-1">
                            <?= htmlspecialchars($company['Name']) ?> · <?= htmlspecialchars($company['Sector'] ?? '未分類產業') ?>
                        </p>
                    </div>
                </div>
                <div class="flex flex-col items-start md:items-end gap-2">
                    <?php if ($jobCat): ?>
                    <span class="text-xs font-bold bg-violet-50 text-violet-700 border border-violet-200 px-3 py-1 rounded-full">職位類型：<?= $jobCat ?></span>
                    <?php endif; ?>
                    <span class="text-sm font-bold <?= str_contains($job['Salary'] ?? '', '面議') ? 'text-slate-400' : 'text-emerald-600' ?>">
                        <?= htmlspecialchars($job['Salary'] ?? '待遇面議') ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ② 面試談薪 -->
        <?php if (!empty($sectorMedians) && count($sectorMedians) >= 3): ?>
        <div id="salary-locator-card" class="bg-white rounded-xl shadow-lg border border-slate-100 p-6 mb-6">
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-3 mb-1">
                <h2 class="text-lg font-bold flex items-center gap-2 text-slate-700">
                    <img src="assets/money.png" class="w-6 h-6 object-contain">
                    面試談薪：這個職缺，你可以開到多少？
                </h2>
                <span class="text-[10px] bg-cyan-50 text-cyan-700 px-2.5 py-1 rounded-full font-bold whitespace-nowrap">
                    基準：<?= htmlspecialchars($company['Sector'] ?? '同產業') ?> 同業 <?= count($sectorMedians) ?> 家公司 · 2024 年非主管全時員工薪資中位數
                </span>
            </div>
            <p class="text-slate-400 text-xs mb-5">拖曳滑桿模擬面試開價，看看這數字在同業談判空間的哪個位置。</p>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">
                <div class="flex flex-col justify-center gap-4">
                    <div>
                        <label for="locator-slider" class="text-sm font-bold text-slate-600">面試期望年薪（萬元／年）</label>
                        <input type="range" id="locator-slider" min="30" max="300" step="1" value="100"
                               class="w-full mt-2 accent-cyan-600 cursor-pointer">
                        <div class="flex justify-between text-xs text-slate-400 mt-1">
                            <span id="locator-min-label"></span><span id="locator-max-label"></span>
                        </div>
                        <div class="flex gap-2 mt-3" id="locator-quick-btns">
                            <button data-quick="conservative" class="quick-btn flex-1 text-sm font-bold py-2 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-100 transition">保守</button>
                            <button data-quick="fair" class="quick-btn flex-1 text-sm font-bold py-2 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-100 transition">合理</button>
                            <button data-quick="aggressive" class="quick-btn flex-1 text-sm font-bold py-2 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-100 transition">進取</button>
                        </div>
                    </div>
                    <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 flex items-center justify-between gap-2">
                        <div>
                            <p class="text-xs font-bold text-slate-500">你的開價</p>
                            <p class="text-3xl font-bold text-violet-600" id="locator-value">—</p>
                            <p class="text-xs text-slate-400">萬 / 年</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs font-bold text-slate-500 mb-1">同業百分位</p>
                            <p class="text-2xl font-bold text-cyan-700" id="locator-percentile">—</p>
                            <p class="text-[10px] text-slate-400" id="locator-desc"></p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col justify-center gap-4">
                    <div id="locator-advice" class="rounded-xl border p-5 transition-all duration-300">
                        <p class="text-sm font-bold mb-1" id="locator-advice-tag">—</p>
                        <p class="text-base leading-relaxed" id="locator-advice-text"></p>
                    </div>
                    <div id="locator-compare-card" class="bg-cyan-50 rounded-xl border border-cyan-200 p-4 text-center">
                        <p class="text-[11px] text-cyan-700/80 mb-1">與這家公司 2024 年中位數相比</p>
                        <p class="text-xl font-bold text-cyan-800" id="locator-compare">—</p>
                    </div>
                </div>
            </div>

            <?php if ($salaryInsight): ?>
            <div class="mt-4 pt-3 border-t border-slate-100 flex items-start gap-2 text-sm text-slate-600 leading-relaxed">
                <span class="mt-1.5 w-3 h-3 rounded-full inline-block flex-shrink-0 <?= $insightRatio >= 2.5 ? 'bg-rose-500' : ($insightRatio >= 1.5 ? 'bg-amber-500' : 'bg-emerald-500') ?>"></span>
                <span>
                    <b class="text-slate-700">分紅結構</b>：新進職缺月薪 <b><?= number_format($insightJobMin) ?> ~ <?= number_format($insightJobMax) ?> 元</b>
                    （<?= $insightCount ?> 筆），
                    全公司等效月薪 <b><?= number_format($insightEquivMonthly) ?> 元</b> —
                    <?= $insightRatio >= 2.5 ? '這家公司收入高度依賴分紅與年資，新進第一年總收入可能遠低於中位數' : ($insightRatio >= 1.5 ? '收入有相當部分來自分紅，中位數僅供長期參考' : '薪資結構以月薪為主，中位數貼近實際月薪水準') ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ③ 職位市場行情 -->
        <?php if ($marketCat): ?>
        <div class="bg-white rounded-xl shadow-lg border border-slate-100 p-6 mb-6">
            <h2 class="text-lg font-bold flex items-center gap-2 text-slate-700 mb-1">
                <i class="fa-solid fa-chart-simple text-violet-500"></i>
                「<?= $jobCat ?>」市場行情
                <span class="text-[10px] bg-violet-50 text-violet-600 border border-violet-200 px-2 py-0.5 rounded-full font-bold"><?= $marketCat['scope'] ?></span>
            </h2>
            <p class="text-slate-400 text-xs mb-5">
                <?= $marketCat['scope'] === '同產業' ? '以同產業（' . htmlspecialchars($company['Sector']) . '）正在招募的職缺開價為基準，這是招募市場的實際月薪帶。' : '同產業樣本不足，改用全台灣正在招募的職缺開價為基準（職位通用性高，全台行情仍具參考價值）。' ?>
            </p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-violet-50 rounded-xl border border-violet-200 p-5 text-center">
                    <p class="text-xs font-bold text-violet-600 mb-1"><?= $marketCat['scope'] ?>「<?= $jobCat ?>」月薪帶</p>
                    <p class="text-2xl font-bold text-violet-700">
                        <?= number_format($marketCat['min']) ?> ~ <?= number_format($marketCat['max']) ?> 元
                    </p>
                    <p class="text-[11px] text-violet-500 mt-1">樣本 <?= $marketCat['count'] ?> 筆職缺</p>
                </div>
                <?php if ($marketCat['jobMin'] !== null): ?>
                <div class="bg-cyan-50 rounded-xl border border-cyan-200 p-5 text-center">
                    <p class="text-xs font-bold text-cyan-700 mb-1">這家公司「<?= $jobCat ?>」職缺開價</p>
                    <p class="text-2xl font-bold text-cyan-800">
                        <?= number_format($marketCat['jobMin']) ?> ~ <?= number_format($marketCat['jobMax']) ?> 元
                    </p>
                    <p class="text-[11px] text-cyan-600 mt-1">月薪（未含分紅）</p>
                </div>
                <?php endif; ?>
                <div class="bg-slate-50 rounded-xl border border-slate-200 p-5 text-center">
                    <p class="text-xs font-bold text-slate-500 mb-1">這家公司全員等效月薪</p>
                    <p class="text-2xl font-bold text-slate-700"><?= number_format(round($company['NonAdminstrativeMedian'] / 12)) ?> 元</p>
                    <p class="text-[11px] text-slate-400 mt-1">中位數年薪 ÷ 12（含分紅攤平）</p>
                </div>
            </div>
            <p class="text-[11px] text-slate-400 mt-4 leading-relaxed">
                💡 提示：如果你的職位是「<?= $jobCat ?>」，市場月薪帶是談判的起點；高分紅公司（紅燈）的實際總收入會明顯高於月薪帶，低分紅公司則貼近月薪帶。
            </p>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </main>

    <footer class="border-t border-slate-200 mt-8 py-8 text-center text-xs text-slate-400">
        <p>JobLens 2026 | 面試談薪資料：公開資訊觀測站薪資中位數 × 104 人力銀行職缺開價</p>
    </footer>

    <script>
        <?php if (!empty($sectorMedians) && count($sectorMedians) >= 3): ?>
        (function initSalaryLocator() {
            const card = document.getElementById('salary-locator-card');
            if (!card) return;
            const sectorData = <?= json_encode($sectorMedians) ?>;
            const companyMedian = <?= isset($company['NonAdminstrativeMedian']) ? round($company['NonAdminstrativeMedian'] / 10000, 1) : 'null' ?>;

            const slider = document.getElementById('locator-slider');
            const valLabel = document.getElementById('locator-value');
            const pctLabel = document.getElementById('locator-percentile');
            const descLabel = document.getElementById('locator-desc');
            const cmpLabel = document.getElementById('locator-compare');
            const adviceBox = document.getElementById('locator-advice');
            const adviceTag = document.getElementById('locator-advice-tag');
            const adviceText = document.getElementById('locator-advice-text');
            const quickBtns = document.querySelectorAll('#locator-quick-btns button');

            const dMin = Math.min(...sectorData), dMax = Math.max(...sectorData);
            const sMin = Math.max(20, Math.floor((dMin - 10) / 10) * 10);
            const sMax = Math.ceil((dMax + 10) / 10) * 10;
            slider.min = sMin; slider.max = sMax; slider.step = 1;
            document.getElementById('locator-min-label').innerText = sMin + ' 萬';
            document.getElementById('locator-max-label').innerText = sMax + ' 萬';

            let baseVal = companyMedian;
            if (baseVal === null || baseVal === undefined) {
                const sorted = [...sectorData].sort((a, b) => a - b);
                baseVal = sorted[Math.floor(sorted.length / 2)];
            }
            const clamp = v => Math.min(Math.max(v, sMin), sMax);
            slider.value = clamp(baseVal);

            function adviceFor(expect, pct) {
                const pctNote = `（同業中也排到第 ${pct} 百分位）`;
                if (companyMedian === null) {
                    if (pct < 40) return { tag: '安全牌', box: 'bg-cyan-50 border-cyan-300', tagCls: 'text-cyan-700', text: '這家公司未揭露薪資中位數，以同業為基準：落在同業前段的安全區。' };
                    if (pct < 60) return { tag: '合理牌', box: 'bg-cyan-50 border-cyan-300', tagCls: 'text-cyan-700', text: '這家公司未揭露薪資中位數，以同業為基準：正落在同業中間。' };
                    if (pct < 85) return { tag: '進取牌', box: 'bg-amber-50 border-amber-300', tagCls: 'text-amber-600', text: '這家公司未揭露薪資中位數，以同業為基準：高於多數同業。' };
                    return { tag: '挑戰者', box: 'bg-rose-50 border-rose-300', tagCls: 'text-rose-600', text: '這家公司未揭露薪資中位數，以同業為基準：高於 9 成同業。' };
                }
                const ratio = expect / companyMedian;
                if (ratio <= 0.9) return {
                    tag: '保守牌', box: 'bg-emerald-50 border-emerald-300', tagCls: 'text-emerald-600',
                    text: `比這家公司中位數低 ${Math.round((1 - ratio) * 100)}%，是相對安全的開價。${pctNote}`
                };
                if (ratio < 1.05) return {
                    tag: '合理牌', box: 'bg-cyan-50 border-cyan-300', tagCls: 'text-cyan-700',
                    text: `緊貼這家公司中位數（${companyMedian} 萬），是談判最合理的區間。${pctNote}`
                };
                if (ratio < 1.3) return {
                    tag: '進取牌', box: 'bg-amber-50 border-amber-300', tagCls: 'text-amber-600',
                    text: `比這家公司中位數高 ${Math.round((ratio - 1) * 100)}%，需要夠力的籌碼支撐。${pctNote}`
                };
                return {
                    tag: '挑戰者', box: 'bg-rose-50 border-rose-300', tagCls: 'text-rose-600',
                    text: `比這家公司中位數高出 ${Math.round((ratio - 1) * 100)}%，除非你有很特別的優勢，否則難度很高。${pctNote}`
                };
            }

            function update(expect) {
                valLabel.innerText = expect.toLocaleString();
                const pct = Math.round(sectorData.filter(v => v <= expect).length / sectorData.length * 100);
                pctLabel.innerText = `第 ${pct} 百分位`;
                descLabel.innerText = `高於同業 ${pct}% 中位數`;

                const adv = adviceFor(expect, pct);
                adviceBox.className = 'rounded-xl border p-5 transition-all duration-300 ' + adv.box;
                adviceTag.className = 'text-sm font-bold mb-1 ' + adv.tagCls;
                adviceTag.innerText = '💡 ' + adv.tag;
                adviceText.innerText = adv.text;

                if (companyMedian !== null) {
                    const diff = expect - companyMedian;
                    const absDiff = Math.abs(diff);
                    if (absDiff < 0.5) {
                        cmpLabel.innerHTML = `約等於公司中位數（${companyMedian} 萬）`;
                    } else {
                        const sign = diff > 0 ? '+' : '-';
                        cmpLabel.innerHTML = `<span class="${diff > 0 ? 'text-emerald-600' : 'text-rose-500'}">${sign}${absDiff.toFixed(0)} 萬</span> <span class="text-slate-400 font-normal text-sm">（公司中位數 ${companyMedian} 萬）</span>`;
                    }
                } else {
                    cmpLabel.innerText = `同業 ${sectorData.length} 家公司中位數介於 ${dMin} ~ ${dMax} 萬`;
                }
            }

            const quickTargets = {
                conservative: clamp(Math.floor(baseVal * 0.9)),
                fair: clamp(Math.round(baseVal)),
                aggressive: clamp(Math.ceil(baseVal * 1.2))
            };
            function setActiveBtn(key) {
                quickBtns.forEach(b => {
                    const active = b.dataset.quick === key;
                    b.className = 'quick-btn flex-1 text-sm font-bold py-2 rounded-lg border transition ' + (active
                        ? 'border-cyan-500 text-cyan-700 bg-cyan-50 shadow-sm'
                        : 'border-slate-300 text-slate-600 hover:bg-slate-100');
                });
            }
            setActiveBtn('fair');
            quickBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    slider.value = quickTargets[btn.dataset.quick];
                    setActiveBtn(btn.dataset.quick);
                    update(parseFloat(slider.value));
                });
            });

            slider.addEventListener('input', () => update(parseFloat(slider.value)));
            update(parseFloat(slider.value));
        })();
        <?php endif; ?>
    </script>
</body>
</html>
