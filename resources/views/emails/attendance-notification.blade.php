<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Yoklama Bildirimi — {{ $classroomName }}</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f1f5f9; padding:20px; margin:0;">
  <table role="presentation" align="center" cellpadding="0" cellspacing="0" width="560" style="background:#ffffff; border-radius:16px; box-shadow:0 1px 3px rgba(0,0,0,0.05); overflow:hidden;">

    {{-- Üst başlık — duruma göre renkli gradient --}}
    @php
      $gradients = [
        'absent'  => 'linear-gradient(135deg,#dc2626,#f97316)',  // kırmızı→turuncu
        'late'    => 'linear-gradient(135deg,#f59e0b,#ea580c)',  // amber→turuncu
        'excused' => 'linear-gradient(135deg,#0ea5e9,#06b6d4)',  // mavi→cyan
      ];
      $gradient = $gradients[$status] ?? 'linear-gradient(135deg,#0ea5e9,#6366f1)';

      $statusBoxColors = [
        'absent'  => ['bg' => '#fef2f2', 'border' => '#dc2626', 'text' => '#7f1d1d'],
        'late'    => ['bg' => '#fffbeb', 'border' => '#f59e0b', 'text' => '#78350f'],
        'excused' => ['bg' => '#eff6ff', 'border' => '#0ea5e9', 'text' => '#0c4a6e'],
      ];
      $sb = $statusBoxColors[$status] ?? ['bg' => '#f1f5f9', 'border' => '#64748b', 'text' => '#0f172a'];
    @endphp

    <tr>
      <td style="background:{{ $gradient }}; padding:32px; text-align:center; color:#ffffff;">
        <h1 style="margin:0; font-size:22px;">SmartRollCall</h1>
        <p style="margin:8px 0 0; opacity:.92; font-size:14px;">Yoklama Bildirimi</p>
      </td>
    </tr>

    <tr>
      <td style="padding:32px; color:#0f172a;">
        <p style="margin:0 0 16px;">Sayın <strong>{{ $studentName }}</strong>,</p>

        <p style="margin:0 0 20px; color:#475569;">
          <strong>{{ $classroomName }}</strong> dersi yoklamasında durumunuz aşağıdaki gibi işaretlenmiştir:
        </p>

        {{-- Bilgi kutusu --}}
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 24px;">
          <tr>
            <td style="padding:14px 16px; background:#f8fafc; border-left:4px solid #0ea5e9; border-radius:8px;">
              <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:14px;">
                <tr>
                  <td style="padding:6px 0; color:#64748b; width:90px;">📚 Ders:</td>
                  <td style="padding:6px 0; color:#0f172a; font-weight:600;">{{ $classroomName }}</td>
                </tr>
                <tr>
                  <td style="padding:6px 0; color:#64748b;">📅 Tarih:</td>
                  <td style="padding:6px 0; color:#0f172a; font-weight:600;">{{ $dateLabel }}</td>
                </tr>
                @if($timeLabel)
                <tr>
                  <td style="padding:6px 0; color:#64748b;">🕐 Saat:</td>
                  <td style="padding:6px 0; color:#0f172a; font-weight:600;">{{ $timeLabel }}</td>
                </tr>
                @endif
                <tr>
                  <td style="padding:6px 0; color:#64748b;">👤 Hoca:</td>
                  <td style="padding:6px 0; color:#0f172a; font-weight:600;">{{ $teacherName }}</td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        {{-- Durum vurgu kutusu --}}
        <div style="text-align:center; margin:24px 0;">
          <div style="display:inline-block; padding:16px 32px; background:{{ $sb['bg'] }}; border:2px solid {{ $sb['border'] }}; border-radius:12px; font-size:18px; font-weight:bold; color:{{ $sb['text'] }};">
            Durum: {{ $statusLabel }}
          </div>
        </div>

        {{-- Devamsızlık özeti (sadece absent için) --}}
        @if($status === 'absent')
          @php
            $left = max(0, $absenceLimit - $absentCount);
            $isOver = $absentCount >= $absenceLimit;
            $isWarning = $left <= 1 && !$isOver;
          @endphp
          <div style="margin:24px 0; padding:14px 16px; background:{{ $isOver ? '#fef2f2' : ($isWarning ? '#fffbeb' : '#f8fafc') }}; border-radius:8px; border:1px solid {{ $isOver ? '#fca5a5' : ($isWarning ? '#fcd34d' : '#e2e8f0') }};">
            @if($isOver)
              <p style="margin:0; color:#7f1d1d; font-size:14px;">
                <strong>⚠️ Devamsızlık Sınırı Aşıldı:</strong> Toplam <strong>{{ $absentCount }}/{{ $absenceLimit }}</strong> devamsızlığınız var. Bu derste başarısız olma riskiniz oluştu.
              </p>
            @elseif($isWarning)
              <p style="margin:0; color:#78350f; font-size:14px;">
                <strong>📊 Sınıra Yaklaştınız:</strong> Toplam <strong>{{ $absentCount }}/{{ $absenceLimit }}</strong> devamsızlığınız var. Sadece <strong>{{ $left }}</strong> hakkınız kaldı.
              </p>
            @else
              <p style="margin:0; color:#475569; font-size:14px;">
                <strong>📊 Devamsızlık Sayısı:</strong> Toplam <strong>{{ $absentCount }}/{{ $absenceLimit }}</strong>. Kalan hak: <strong>{{ $left }}</strong>.
              </p>
            @endif
          </div>
        @endif

        {{-- Mazeret yönlendirmesi (absent ya da late için) --}}
        @if(in_array($status, ['absent', 'late']))
          <div style="margin:24px 0; padding:14px 16px; background:#eff6ff; border-radius:8px; border:1px solid #bfdbfe;">
            <p style="margin:0; color:#1e3a8a; font-size:14px;">
              <strong>📎 Geçerli mazeretiniz mi var?</strong><br>
              Öğrenci panelinizden <strong>"Mazeretlerim"</strong> sayfasına girip rapor yükleyebilir; hocanız onaylarsa bu kayıt <strong>"Mazeretli"</strong>ye dönüşür ve devamsızlık hakkınızdan düşmez.
            </p>
          </div>
        @endif

        @if($status === 'excused')
          <p style="margin:16px 0; color:#475569; font-size:14px;">
            ℹ️ Mazeretli sayıldınız — bu kayıt devamsızlık hakkınızdan düşmez.
          </p>
        @endif

        <p style="margin:24px 0 0; color:#94a3b8; font-size:12px;">
          Bu e-posta, hocanız tarafından yoklama alındığında otomatik olarak gönderilmiştir.
          Yanıtlamanıza gerek yoktur.
        </p>
      </td>
    </tr>

    <tr>
      <td style="padding:16px 32px; background:#f8fafc; color:#94a3b8; font-size:12px; text-align:center;">
        SmartRollCall &middot; Otomatik Yoklama Bildirimi
      </td>
    </tr>
  </table>
</body>
</html>
