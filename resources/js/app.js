const exportButton = document.getElementById('export-usage');
if (exportButton) {
    exportButton.addEventListener('click', () => {
        const days = JSON.parse(document.getElementById('usage-data').textContent);
        const csv = ['Date (UTC),Usage units', ...days.map(day => `${day.date},${day.units}`)].join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'merchant-daily-usage.csv';
        link.click();
        URL.revokeObjectURL(url);
    });
}
