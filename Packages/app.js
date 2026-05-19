// State management for calculations
const state = {
    calculated: false,
    systemCost: 0,
    yearlySaving: 0
};

// DOM Elements
const solarForm = document.getElementById('solar-form');
const monthlyBillInput = document.getElementById('monthly-bill');
const buildingTypeSelect = document.getElementById('building-type');
const daytimeUseInput = document.getElementById('daytime-use');
const dayPercentLabel = document.getElementById('day-percent');
const nightPercentLabel = document.getElementById('night-percent');
const billHint = document.getElementById('bill-hint');
const buildingHint = document.getElementById('building-hint');

const resultsPlaceholder = document.getElementById('results-placeholder');
const resultsContent = document.getElementById('results-content');
const tabs = document.querySelectorAll('.tab');
const tabPanels = document.querySelectorAll('.tab-panel');
const savingChartCanvas = document.getElementById('saving-chart');

// Cost per kW curve based on system size in kW
function getCostPerKw(size) {
    return 28500; // 28.5 Baht per Watt = 28,500 Baht per kW
}

// Update Range Labels
function updateRangeLabels() {
    const dayVal = parseInt(daytimeUseInput.value, 10);
    const nightVal = 100 - dayVal;
    dayPercentLabel.textContent = `${dayVal}%`;
    nightPercentLabel.textContent = `${nightVal}%`;
}

// Update Bill Hint
function updateBillHint() {
    const val = monthlyBillInput.value;
    monthlyBillInput.classList.remove('input-error');
    if (!val) {
        billHint.textContent = 'กรุณากรอกค่าไฟฟ้าต่อเดือน';
        billHint.className = 'field-hint error';
        billHint.style.display = 'block';
        monthlyBillInput.classList.add('input-error');
        return;
    }
    if (val >= 500) {
        billHint.textContent = `ดี! ค่าไฟ ${Number(val).toLocaleString('th-TH')} บาท/เดือน`;
        billHint.className = 'field-hint success';
        billHint.style.display = 'block';
    } else {
        billHint.textContent = `ค่าไฟฟ้าต่ำกว่า 500 บาท อาจไม่คุ้มค่าติดตั้ง`;
        billHint.className = 'field-hint';
        billHint.style.display = 'block';
    }
}

// Update Building Hint
function updateBuildingHint() {
    const selectedIdx = buildingTypeSelect.selectedIndex;
    buildingTypeSelect.classList.remove('input-error');
    if (selectedIdx > 0) {
        const text = buildingTypeSelect.options[selectedIdx].text;
        buildingHint.textContent = `เลือก${text}แล้ว`;
        buildingHint.className = 'field-hint success';
        buildingHint.style.display = 'block';
    } else {
        buildingHint.textContent = 'กรุณาเลือกประเภทอาคาร';
        buildingHint.className = 'field-hint error';
        buildingHint.style.display = 'block';
        buildingTypeSelect.classList.add('input-error');
    }
}

// Event Listeners for Input Feedback
daytimeUseInput.addEventListener('input', updateRangeLabels);
daytimeUseInput.addEventListener('change', updateRangeLabels);
monthlyBillInput.addEventListener('input', updateBillHint);
buildingTypeSelect.addEventListener('change', updateBuildingHint);

// Tab Navigation Listener
tabs.forEach(tab => {
    tab.addEventListener('click', () => {
        // Toggle Active Tab
        tabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');

        // Toggle Active Panel
        const targetTab = tab.getAttribute('data-tab');
        tabPanels.forEach(panel => {
            if (panel.id === `${targetTab}-panel`) {
                panel.classList.add('active');
            } else {
                panel.classList.remove('active');
            }
        });

        // If switching to chart, redraw it to fit layout
        if (targetTab === 'chart' && state.calculated) {
            drawChart(savingChartCanvas, state.systemCost, state.yearlySaving);
        }
    });
});

// Window Resize Redraw
window.addEventListener('resize', () => {
    if (state.calculated && document.getElementById('chart-panel').classList.contains('active')) {
        drawChart(savingChartCanvas, state.systemCost, state.yearlySaving);
    }
});

// Canvas Chart Drawer
function drawChart(canvas, systemCost, yearlySaving) {
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    // Get display size
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    canvas.width = rect.width * dpr;
    canvas.height = 360 * dpr;
    ctx.scale(dpr, dpr);

    const width = rect.width;
    const height = 360;

    // Clear
    ctx.clearRect(0, 0, width, height);

    const padding = { top: 40, right: 40, bottom: 40, left: 80 };
    const chartWidth = width - padding.left - padding.right;
    const chartHeight = height - padding.top - padding.bottom;

    const years = 25;
    const dataPoints = [0];
    let cum = 0;
    let s = yearlySaving;
    for (let i = 1; i <= years; i++) {
        cum += s;
        dataPoints.push(cum);
        s *= 0.99; // 1% solar degradation per year
    }

    const maxVal = Math.max(systemCost, dataPoints[25]) * 1.1;

    const getX = (index) => padding.left + (index / years) * chartWidth;
    const getY = (val) => padding.top + chartHeight - (val / maxVal) * chartHeight;

    // Draw Y Grid lines and labels
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 1;
    ctx.fillStyle = '#64748b';
    ctx.font = '12px Tahoma, "Segoe UI", sans-serif';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';

    const ticks = 5;
    for (let i = 0; i <= ticks; i++) {
        const val = (maxVal / ticks) * i;
        const y = getY(val);
        
        ctx.beginPath();
        ctx.moveTo(padding.left, y);
        ctx.lineTo(width - padding.right, y);
        ctx.stroke();

        let labelText = '';
        if (val >= 1000000) {
            labelText = (val / 1000000).toFixed(1) + 'M ฿';
        } else {
            labelText = (val / 1000).toFixed(0) + 'k ฿';
        }
        ctx.fillText(labelText, padding.left - 10, y);
    }

    // Draw X Grid lines and labels
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    for (let i = 0; i <= years; i += 5) {
        const x = getX(i);
        ctx.fillText(`ปีที่ ${i}`, x, padding.top + chartHeight + 10);
    }

    // Draw area under cumulative savings
    const gradient = ctx.createLinearGradient(0, padding.top, 0, padding.top + chartHeight);
    gradient.addColorStop(0, 'rgba(22, 184, 97, 0.25)');
    gradient.addColorStop(1, 'rgba(22, 184, 97, 0.00)');

    ctx.fillStyle = gradient;
    ctx.beginPath();
    ctx.moveTo(getX(0), getY(0));
    for (let i = 1; i <= years; i++) {
        ctx.lineTo(getX(i), getY(dataPoints[i]));
    }
    ctx.lineTo(getX(years), getY(0));
    ctx.closePath();
    ctx.fill();

    // Draw Cumulative Savings line
    ctx.strokeStyle = '#16b861';
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.moveTo(getX(0), getY(0));
    for (let i = 1; i <= years; i++) {
        ctx.lineTo(getX(i), getY(dataPoints[i]));
    }
    ctx.stroke();

    // Draw Investment Cost line (flat line)
    ctx.strokeStyle = '#ef2929';
    ctx.lineWidth = 2;
    ctx.setLineDash([5, 5]);
    ctx.beginPath();
    ctx.moveTo(padding.left, getY(systemCost));
    ctx.lineTo(width - padding.right, getY(systemCost));
    ctx.stroke();
    ctx.setLineDash([]); // Reset dash

    // Draw intersection / payback point
    let exactPaybackYear = 0;
    let c = 0;
    let s2 = yearlySaving;
    for (let i = 1; i <= years; i++) {
        const nextC = c + s2;
        if (systemCost >= c && systemCost <= nextC) {
            const fraction = (systemCost - c) / s2;
            exactPaybackYear = (i - 1) + fraction;
            break;
        }
        c = nextC;
        s2 *= 0.99; // 1% solar degradation per year
    }
    
    if (exactPaybackYear > 0 && exactPaybackYear <= years) {
        const intersectX = getX(exactPaybackYear);
        const intersectY = getY(systemCost);

        ctx.fillStyle = '#10b981';
        ctx.beginPath();
        ctx.arc(intersectX, intersectY, 6, 0, 2 * Math.PI);
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 2;
        ctx.stroke();

        ctx.fillStyle = '#0f2036';
        ctx.font = 'bold 11px Tahoma, sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(`จุดคืนทุน ${exactPaybackYear.toFixed(1)} ปี`, intersectX + 10, intersectY - 10);
    }

    // Draw legends
    ctx.font = '12px Tahoma, sans-serif';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    
    ctx.fillStyle = '#16b861';
    ctx.fillRect(padding.left + 20, 15, 12, 12);
    ctx.fillStyle = '#0f2036';
    ctx.fillText('เงินประหยัดสะสม', padding.left + 38, 21);

    ctx.strokeStyle = '#ef2929';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(padding.left + 150, 21);
    ctx.lineTo(padding.left + 162, 21);
    ctx.stroke();
    ctx.fillStyle = '#0f2036';
    ctx.fillText('เงินลงทุนเริ่มต้น', padding.left + 168, 21);
}

// Calculate and submit form
solarForm.addEventListener('submit', (e) => {
    e.preventDefault();

    const billVal = monthlyBillInput.value;
    const typeVal = buildingTypeSelect.value;
    let hasError = false;

    if (!billVal) {
        billHint.textContent = 'กรุณากรอกค่าไฟฟ้าต่อเดือน';
        billHint.className = 'field-hint error';
        billHint.style.display = 'block';
        monthlyBillInput.classList.add('input-error');
        hasError = true;
    }
    if (!typeVal) {
        buildingHint.textContent = 'กรุณาเลือกประเภทอาคาร';
        buildingHint.className = 'field-hint error';
        buildingHint.style.display = 'block';
        buildingTypeSelect.classList.add('input-error');
        hasError = true;
    }

    if (hasError) {
        if (!billVal) {
            monthlyBillInput.focus();
        } else {
            buildingTypeSelect.focus();
        }
        return;
    }

    const monthlyBill = parseFloat(billVal);
    const buildingType = typeVal;
    const daytimeUse = parseFloat(daytimeUseInput.value);

    // Get selected Phase
    let phase = 'three';
    const phaseRadio = document.querySelector('input[name="phase"]:checked');
    if (phaseRadio) {
        phase = phaseRadio.value;
    }

    // Tariff mapping
    const tariffs = {
        factory: 4.2,
        office: 4.4,
        retail: 4.5,
        home: 4.7,
        warehouse: 4.3
    };
    const tariff = tariffs[buildingType] || 4.5;

    // Safety margins based on building load profiles
    const safetyFactors = {
        factory: 0.5265,
        office: 0.55,
        retail: 0.55,
        warehouse: 0.50,
        home: 0.60
    };
    const safetyFactor = safetyFactors[buildingType] || 0.55;

    // Calculation Constants
    const annualYieldPerKW = 1449;
    const panelWatt = 650; // W per panel (650W yields exactly 8, 16, 32, 64 panels for 5, 10, 20, 40 kWp)

    // Sizing recommendation mapping from user rules:
    // ค่าไฟ 3000 -> 5 kWp
    // ค่าไฟ 6000 -> 10 kWp
    // ค่าไฟ 10000 -> 20 kWp
    // ค่าไฟ 20000 -> 40 kWp
    let maxSize;
    if (monthlyBill <= 3000) {
        maxSize = (monthlyBill / 3000) * 5;
    } else if (monthlyBill <= 6000) {
        maxSize = 5 + ((monthlyBill - 3000) / 3000) * 5;
    } else if (monthlyBill <= 10000) {
        maxSize = 10 + ((monthlyBill - 6000) / 4000) * 10;
    } else if (monthlyBill <= 20000) {
        maxSize = 20 + ((monthlyBill - 10000) / 10000) * 20;
    } else {
        maxSize = 40 + ((monthlyBill - 20000) / 20000) * 40;
    }

    // Scale system size based on daytimeUse (normalized so 95% is the full max size)
    let sizeKW = maxSize * (daytimeUse / 95);
    
    // Apply grid constraints based on Phase (1-phase capped at 5kW in Thailand)
    if (phase === 'single') {
        sizeKW = Math.min(5, sizeKW);
    }

    // Round to nearest 0.5 kW for realism
    sizeKW = Math.round(sizeKW * 2) / 2;
    if (sizeKW < 1.0) sizeKW = 1.0; // Min system size

    const panelCount = Math.round(sizeKW * 1.6);
    const roofArea = Math.round(panelCount * 2.7); // 2.7 sq.m per 650W panel including spacing

    // System Pricing (28.5 Baht per Watt = 28,500 Baht per kW)
    const costPerKw = 28500;
    const systemCost = sizeKW * costPerKw;

    // Yield and Savings
    const cleanEnergy = Math.round(sizeKW * annualYieldPerKW);
    
    // ประหยัด/เดือน = KW * 4 * 30 * 5
    let monthlySaving = Math.round(sizeKW * 4 * 30 * 5);
    
    // ไม่ให้ประหยัดเกินค่าไฟรวม
    if (monthlySaving > monthlyBill) {
        monthlySaving = monthlyBill;
    }
    
    let yearlySaving = monthlySaving * 12;
    const savingRate = Math.round((monthlySaving / monthlyBill) * 100);

    const payback = (systemCost / yearlySaving).toFixed(1);

    // 25 Years Cumulative Calculations (accounting for 1% yearly panel degradation)
    let totalSaving = 0;
    let tempSaving = yearlySaving;
    for (let year = 1; year <= 25; year++) {
        totalSaving += tempSaving;
        tempSaving *= 0.99; // 1% solar degradation per year
    }
    totalSaving = Math.round(totalSaving);
    const netProfit = totalSaving - systemCost;
    const roi = Math.round((netProfit / systemCost) * 100);

    // Environmental Impact
    const co2 = (cleanEnergy * 0.000414).toFixed(1);
    const trees = Math.round(parseFloat(co2) * 1000 / 22);

    // Update DOM Results
    document.getElementById('system-size').textContent = `${sizeKW} kW`;
    document.getElementById('panel-count').textContent = `${panelCount} แผง`;
    document.getElementById('roof-area').textContent = `${roofArea} ตร.ม.`;
    document.getElementById('panel-watt').textContent = `${panelWatt}W`;
    
    document.getElementById('monthly-saving').textContent = `฿${monthlySaving.toLocaleString('th-TH')}`;
    document.getElementById('yearly-saving').textContent = `฿${yearlySaving.toLocaleString('th-TH')}`;
    document.getElementById('saving-rate').textContent = `${savingRate}%`;
    document.getElementById('payback').textContent = `${payback} ปี`;

    document.getElementById('system-cost').textContent = `฿${systemCost.toLocaleString('th-TH')}`;
    document.getElementById('cost-per-kw').textContent = `฿${costPerKw.toLocaleString('th-TH')}`;

    document.getElementById('total-saving').textContent = `฿${totalSaving.toLocaleString('th-TH')}`;
    document.getElementById('net-profit').textContent = `฿${netProfit.toLocaleString('th-TH')}`;
    document.getElementById('roi').textContent = `${roi}%`;

    document.getElementById('co2').textContent = `${co2} ตัน`;
    document.getElementById('trees').textContent = `${trees.toLocaleString('th-TH')} ต้น`;
    document.getElementById('clean-energy').textContent = `${cleanEnergy.toLocaleString('th-TH')} kWh`;

    // Populate Year Table
    const tableBody = document.getElementById('year-table');
    tableBody.innerHTML = '';
    
    let cumulative = -systemCost;
    let currentYearlySaving = yearlySaving;
    for (let year = 1; year <= 25; year++) {
        cumulative += currentYearlySaving;
        const row = document.createElement('tr');
        
        const cellYear = document.createElement('td');
        cellYear.textContent = year;
        cellYear.style.textAlign = 'left';
        
        const cellSaving = document.createElement('td');
        cellSaving.textContent = `฿${Math.round(currentYearlySaving).toLocaleString('th-TH')}`;
        
        const cellCumulative = document.createElement('td');
        if (cumulative < 0) {
            cellCumulative.textContent = `-฿${Math.round(Math.abs(cumulative)).toLocaleString('th-TH')}`;
            cellCumulative.style.color = '#ef2929';
        } else {
            cellCumulative.textContent = `+฿${Math.round(cumulative).toLocaleString('th-TH')}`;
            cellCumulative.style.color = '#16b861';
            cellCumulative.style.fontWeight = 'bold';
        }
        
        row.appendChild(cellYear);
        row.appendChild(cellSaving);
        row.appendChild(cellCumulative);
        tableBody.appendChild(row);
        
        currentYearlySaving *= 0.99; // 1% solar degradation per year
    }

    // Save state for redraws
    state.calculated = true;
    state.systemCost = systemCost;
    state.yearlySaving = yearlySaving;

    // Show results
    resultsPlaceholder.style.display = 'none';
    resultsContent.style.display = 'block';

    // Reset back to Summary tab on calculation
    tabs.forEach(t => t.classList.remove('active'));
    document.querySelector('.tab[data-tab="summary"]').classList.add('active');
    tabPanels.forEach(panel => {
        if (panel.id === 'summary-panel') {
            panel.classList.add('active');
        } else {
            panel.classList.remove('active');
        }
    });

    // Smooth scroll to results content
    resultsContent.scrollIntoView({ behavior: 'smooth' });
});

// Packages Tab Switching
document.addEventListener('DOMContentLoaded', () => {
    const pkgTabs = document.querySelectorAll('.package-tab');
    const pkgGrids = document.querySelectorAll('.package-grid');
    pkgTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            pkgTabs.forEach(t => t.classList.remove('active'));
            pkgGrids.forEach(g => {
                g.style.display = 'none';
                g.classList.remove('active');
            });
            tab.classList.add('active');
            const targetId = `pkg-${tab.dataset.tab}`;
            const targetGrid = document.getElementById(targetId);
            if (targetGrid) {
                targetGrid.style.display = 'grid';
                targetGrid.classList.add('active');
            }
        });
    });
});
