<?php
/** Shared look of CHIMBO's PDFs (receipt, packing slip). Dompdf supports tables and simple CSS, no flexbox. */
?>
<style>
    @page { margin: 28px 32px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2a24; }
    .header { background: #0e3b2a; color: #fff; padding: 16px 20px; }
    .brand { font-size: 24px; font-weight: bold; letter-spacing: 1px; }
    .brand-orange { color: #f39200; }
    .tagline { font-size: 8px; letter-spacing: 1px; margin-top: 2px; color: #d7e4dc; }
    .title { font-size: 16px; font-weight: bold; text-align: right; }
    .muted { color: #6b7570; }
    .section { margin-top: 16px; }
    .box-title { font-size: 9px; font-weight: bold; color: #6b7570; text-transform: uppercase; margin-bottom: 4px; }
    table { width: 100%; border-collapse: collapse; }
    .items th { background: #fbf6ee; text-align: left; font-size: 9px; text-transform: uppercase; color: #6b7570; padding: 7px 6px; }
    .items td { padding: 7px 6px; border-bottom: 1px solid #ece6db; vertical-align: top; }
    .right, .items th.right { text-align: right; }
    .totals td { padding: 4px 6px; }
    .grand-total td { font-size: 13px; font-weight: bold; border-top: 2px solid #0e3b2a; padding-top: 8px; }
    .stamp { color: #b42318; border: 2px solid #b42318; padding: 4px 10px; font-weight: bold; display: inline-block; }
    .order-number { font-size: 26px; font-weight: bold; letter-spacing: 1px; }
    .check { width: 14px; height: 14px; border: 1.5px solid #1f2a24; display: inline-block; }
    .collect { background: #fdead0; border: 2px solid #f39200; padding: 8px 12px; font-size: 14px; font-weight: bold; }
    .note { background: #fbf6ee; padding: 8px 10px; }
    .signature td { padding-top: 26px; }
    .footer { margin-top: 28px; text-align: center; font-size: 9px; color: #6b7570; }
</style>
