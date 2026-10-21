import { currentCompany, currentYear, session } from './auth';
import { inr, qty } from './api';

/*
 * Printing without the server: build a clean A4 page from the data already on the screen and print it
 * (the browser print dialog also has "Save as PDF").
 *  - printRegister : a list with a period, a total row and a company header
 *  - printDocument : one bill / voucher with items and totals
 * Every value is escaped, so a product or customer name can never break (or inject into) the page.
 */
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export const fmtDate = (iso) => {
    const m = String(iso || '').slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})$/);

    return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
};

const CSS = `
  @page { size: A4; margin: 14mm 12mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 12px; margin: 0; }
  .head { text-align: center; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 10px; }
  .head h1 { margin: 0; font-size: 20px; letter-spacing: .03em; }
  .head div { color: #444; margin-top: 2px; }
  .title { display: flex; justify-content: space-between; align-items: baseline; margin: 10px 0 8px; }
  .title h2 { margin: 0; font-size: 15px; text-transform: uppercase; letter-spacing: .06em; }
  .meta { color: #333; }
  .info { display: flex; justify-content: space-between; gap: 20px; margin: 8px 0 12px; }
  .info div { line-height: 1.6; } .info .r { text-align: right; }
  table { width: 100%; border-collapse: collapse; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  th, td { border: 1px solid #999; padding: 5px 7px; vertical-align: top; }
  th { background: #eee; text-align: left; font-size: 11px; text-transform: uppercase; }
  .num { text-align: right; white-space: nowrap; }
  tfoot td { font-weight: bold; background: #f6f6f6; }
  .sum { margin-top: 10px; display: flex; gap: 22px; flex-wrap: wrap; }
  .totals { margin: 10px 0 0 auto; width: 260px; }
  .totals div { display: flex; justify-content: space-between; padding: 4px 0; }
  .totals .grand { border-top: 2px solid #111; font-weight: bold; font-size: 14px; }
  .sign { display: flex; justify-content: space-between; margin-top: 54px; }
  .sign div { border-top: 1px solid #111; padding-top: 4px; width: 170px; text-align: center; }
  .foot { margin-top: 16px; color: #666; font-size: 10px; display: flex; justify-content: space-between; }
`;

const header = () => {
    const c = currentCompany();
    const line = [c?.address, c?.phone ? `Ph: ${c.phone}` : '', c?.gstin ? `GSTIN: ${c.gstin}` : ''].filter(Boolean).join('  ·  ');

    return `<div class="head"><h1>${esc(c?.name || 'MK Electrical')}</h1>${line ? `<div>${esc(line)}</div>` : ''}</div>`;
};

const footer = () =>
    `<div class="foot"><span>Account year ${esc(currentYear()?.label)} · Printed by ${esc(session.user?.name)}</span><span>${esc(new Date().toLocaleString('en-IN'))}</span></div>`;

const page = (title, body) =>
    `<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title><style>${CSS}</style></head><body>${header()}${body}${footer()}</body></html>`;

/** print through a hidden iframe: no pop-up window, so pop-up blockers cannot stop it */
export const printHtml = (html) => {
    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
    document.body.appendChild(frame);

    const doc = frame.contentWindow.document;
    doc.open();
    doc.write(html);
    doc.close();

    const run = () => {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    };
    setTimeout(run, 300);
    frame.contentWindow.addEventListener('afterprint', () => setTimeout(() => frame.remove(), 500));
    setTimeout(() => frame.remove(), 5 * 60 * 1000);   // never leave it behind

    return frame;
};

const cell = (col, row, i) => {
    const v = col.get(row, i);

    return `<td class="${col.num ? 'num' : ''}">${esc(v)}</td>`;
};

/**
 * @param {{title: string, period?: {from: string, to: string}, columns: {label: string, get: Function, num?: boolean}[],
 *          rows: any[], footer?: any[], summary?: string[]}} o
 *  footer = one value per column (use '' for empty cells)
 */
export const printRegister = (o) => {
    const period = o.period ? `Period: ${fmtDate(o.period.from)} to ${fmtDate(o.period.to)}` : '';
    const head = o.columns.map((c) => `<th class="${c.num ? 'num' : ''}">${esc(c.label)}</th>`).join('');
    const body = o.rows.length
        ? o.rows.map((r, i) => `<tr>${o.columns.map((c) => cell(c, r, i)).join('')}</tr>`).join('')
        : `<tr><td colspan="${o.columns.length}" style="text-align:center;padding:16px">No records in this period</td></tr>`;
    const foot = o.footer ? `<tfoot><tr>${o.footer.map((v, i) => `<td class="${o.columns[i]?.num ? 'num' : ''}">${esc(v)}</td>`).join('')}</tr></tfoot>` : '';
    const sum = o.summary?.length ? `<div class="sum">${o.summary.map((t) => `<span>${esc(t)}</span>`).join('')}</div>` : '';

    return printHtml(page(o.title, `
        <div class="title"><h2>${esc(o.title)}</h2><span class="meta">${esc(period)} · ${o.rows.length} record${o.rows.length === 1 ? '' : 's'}</span></div>
        <table><thead><tr>${head}</tr></thead><tbody>${body}</tbody>${foot}</table>${sum}`));
};

/**
 * One bill / voucher.
 * @param {{title: string, number: string, date: string, left: string[][], right?: string[][], columns: any[], rows: any[],
 *          totals: string[][], notes?: string, signatures?: string[]}} o   left/right = [label, value] lines above the table
 */
export const printDocument = (o) => {
    const lines = (list) => (list || []).filter(([, v]) => v !== '' && v != null).map(([l, v]) => `<div><b>${esc(l)}:</b> ${esc(v)}</div>`).join('');
    const head = o.columns.map((c) => `<th class="${c.num ? 'num' : ''}">${esc(c.label)}</th>`).join('');
    const body = o.rows.map((r, i) => `<tr>${o.columns.map((c) => cell(c, r, i)).join('')}</tr>`).join('');
    const totals = o.totals.map(([l, v], i) => `<div class="${i === o.totals.length - 1 ? 'grand' : ''}"><span>${esc(l)}</span><span>${esc(v)}</span></div>`).join('');
    const sign = (o.signatures || ['Prepared by', 'Authorised signature']).map((s) => `<div>${esc(s)}</div>`).join('');

    return printHtml(page(`${o.title} ${o.number}`, `
        <div class="title"><h2>${esc(o.title)}</h2><span class="meta">No: <b>${esc(o.number)}</b> · Date: <b>${esc(fmtDate(o.date))}</b></span></div>
        <div class="info"><div>${lines(o.left)}</div><div class="r">${lines(o.right)}</div></div>
        <table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>
        <div class="totals">${totals}</div>
        ${o.notes ? `<p><b>Notes:</b> ${esc(o.notes)}</p>` : ''}
        <div class="sign">${sign}</div>`));
};

export { inr, qty };
