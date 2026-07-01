<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('app.pawn.view.slip_preview') }} - {{ $document['slip']['slipNo'] }}</title>
    <style>
        @page {
            size: {{ $paper['widthMm'] }}mm {{ $paper['heightMm'] }}mm;
            margin: {{ $paper['marginMm']['top'] }}mm {{ $paper['marginMm']['right'] }}mm {{ $paper['marginMm']['bottom'] }}mm {{ $paper['marginMm']['left'] }}mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111827;
            background: #f3f4f6;
            font-family: {!! $fontStack !!};
        }

        .page {
            width: 100%;
            max-width: {{ $paper['widthMm'] }}mm;
            min-height: {{ $paper['heightMm'] }}mm;
            margin: 0 auto;
            padding: {{ $paper['marginMm']['top'] }}mm {{ $paper['marginMm']['right'] }}mm {{ $paper['marginMm']['bottom'] }}mm {{ $paper['marginMm']['left'] }}mm;
            background: #ffffff;
            overflow-wrap: anywhere;
        }

        .section {
            margin-bottom: 4mm;
        }

        header.section,
        footer.section {
            min-height: 32mm;
            position: relative;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 70mm), 1fr));
            gap: 3mm;
            margin-bottom: 4mm;
        }

        .meta-card {
            border: 0.3mm solid #d1d5db;
            padding: 3mm;
            border-radius: 1.5mm;
        }

        .meta-card h3 {
            margin: 0 0 2mm;
            font-size: 10pt;
        }

        .meta-line {
            font-size: 9pt;
            margin-bottom: 1mm;
        }

        .items-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            margin-top: 3mm;
        }

        .items-table th,
        .items-table td {
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .items-table th {
            background: #f9fafb;
            text-align: left;
        }

        .summary {
            margin-top: 4mm;
            width: 100%;
            max-width: min(100%, 70mm);
            margin-left: auto;
        }


        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 1.5mm 0;
            border-bottom: 0.3mm solid #d1d5db;
            font-size: 9pt;
        }

        .notes {
            margin-top: 4mm;
            border: 0.3mm solid #d1d5db;
            padding: 3mm;
            min-height: 16mm;
            font-size: 9pt;
        }
        @media screen {
            body {
                padding: 4mm;
            }
        }

        @media screen and (max-width: 90mm) {
            .meta-grid {
                grid-template-columns: 1fr;
            }

            .items-table th,
            .items-table td {
                padding: 1.2mm;
                font-size: 7.5pt;
            }

            .meta-card h3 {
                font-size: 9pt;
            }

            .meta-line,
            .summary-row,
            .notes {
                font-size: 8pt;
            }

            header.section,
            footer.section {
                min-height: 20mm;
            }
        }

        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }

            .page {
                width: {{ $paper['widthMm'] }}mm;
                max-width: none;
                margin: 0;
                min-height: auto;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <header class="section">{!! $headerHtml !!}</header>

        <section class="section meta-grid">
            <div class="meta-card">
                <h3>{{ __('app.pawn.view.slip_detail') }}</h3>
                <div class="meta-line"><strong>{{ __('app.pawn.view.slip_no') }}:</strong> {{ $document['slip']['slipNo'] }}</div>
                <div class="meta-line"><strong>{{ __('app.pawn.view.created_date') }}:</strong> {{ \Carbon\CarbonImmutable::parse($document['slip']['createdAt'])->toDateString() }}</div>
                <div class="meta-line"><strong>{{ __('app.pawn.view.expire_date') }}:</strong> {{ \Carbon\CarbonImmutable::parse($document['slip']['expireAt'])->toDateString() }}</div>
                <div class="meta-line"><strong>{{ __('app.common.view.labels.status') }}:</strong> {{ $document['slip']['status'] }}</div>
                <div class="meta-line"><strong>{{ __('app.pawn.view.interest_type') }}:</strong> {{ $document['slip']['interestType'] }}</div>
            </div>
            <div class="meta-card">
                <h3>{{ __('app.pawn.view.customer_detail') }}</h3>
                <div class="meta-line"><strong>{{ __('app.common.view.labels.name') }}:</strong> {{ $document['customer']['name'] }}</div>
                <div class="meta-line"><strong>{{ __('app.common.view.labels.phone') }}:</strong> {{ $document['customer']['phone'] }}</div>
                <div class="meta-line"><strong>{{ __('app.common.view.labels.email') }}:</strong> {{ $document['customer']['email'] }}</div>
                <div class="meta-line"><strong>{{ __('app.common.view.labels.address') }}:</strong> {{ $document['customer']['address'] }}</div>
            </div>
        </section>

        <section class="section">
            <h3 style="margin: 0 0 2mm;">{{ __('app.pawn.view.collateral_items') }}</h3>
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">#</th>
                        <th style="width: 24%;">{{ __('app.common.view.labels.name') }}</th>
                        <th style="width: 12%;">{{ __('app.common.view.labels.type') }}</th>
                        <th style="width: 10%;">{{ __('app.pawn.view.quantity') }}</th>
                        <th style="width: 18%;">{{ __('app.common.view.labels.description') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($document['items'] as $index => $item)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $item['name'] }}</td>
                            <td>{{ $item['type'] }}</td>
                            <td>{{ $item['quantity'] }}</td>
                            <td>{{ $item['description'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="summary">
            <div class="summary-row">
                <span>{{ __('app.pawn.view.loan_amount') }}</span>
                <strong>{{ $document['slip']['loanAmount'] }}</strong>
            </div>
            <div class="summary-row">
                <span>{{ __('app.pawn.view.interest_rate') }}</span>
                <strong>{{ $document['slip']['interestRate'] }}%</strong>
            </div>
        </section>

        <section class="notes">
            <strong>{{ __('app.pawn.view.notes') }}</strong><br>
            {{ $document['slip']['notes'] }}
        </section>

        <footer class="section">{!! $footerHtml !!}</footer>
    </div>
</body>
</html>
