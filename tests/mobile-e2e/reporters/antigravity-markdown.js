const fs = require('fs');
const path = require('path');

class AntigravityMarkdownReporter {
  constructor() {
    this.rows = [];
  }

  onTestEnd(test, result) {
    this.rows.push({
      title: test.titlePath().join(' › '),
      status: result.status,
      duration: result.duration,
      error: result.errors && result.errors[0] ? result.errors[0].message : '',
    });
  }

  onEnd(result) {
    const counts = this.rows.reduce((acc, row) => {
      acc[row.status] = (acc[row.status] || 0) + 1;
      return acc;
    }, {});
    const lines = [
      '# Le Cayenne — mobile E2E latest',
      '',
      `- Generated: ${new Date().toISOString()}`,
      `- Overall status: **${result.status}**`,
      `- Tests: ${this.rows.length} (passed ${counts.passed || 0}, failed ${counts.failed || 0}, skipped ${counts.skipped || 0}, timed out ${counts.timedOut || 0})`,
      '',
      '## Results',
      '',
      '| Status | Test | Duration |',
      '| --- | --- | ---: |',
    ];
    for (const row of this.rows) {
      const title = row.title.replace(/\|/g, '\\|');
      const suffix = row.error ? ` — ${row.error.split('\n')[0].replace(/\|/g, '\\|')}` : '';
      lines.push(`| ${row.status} | ${title}${suffix} | ${row.duration} ms |`);
    }
    const target = path.resolve(__dirname, '../../../reports/antigravity/latest.md');
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, lines.join('\n') + '\n');
  }
}

module.exports = AntigravityMarkdownReporter;
