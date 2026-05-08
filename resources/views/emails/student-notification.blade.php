<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>{{ $classroomName }} — Bildirim</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f1f5f9; padding:20px; margin:0;">
  <table role="presentation" align="center" cellpadding="0" cellspacing="0" width="560" style="background:#ffffff; border-radius:16px; box-shadow:0 1px 3px rgba(0,0,0,0.05); overflow:hidden;">
    <tr>
      <td style="background:#0ea5e9; padding:24px 32px; color:#ffffff;">
        <p style="margin:0; opacity:.85; font-size:13px;">{{ $teacherName }} • SmartRollCall</p>
        <h2 style="margin:6px 0 0; font-size:20px;">{{ $classroomName }}</h2>
      </td>
    </tr>
    <tr>
      <td style="padding:32px; color:#0f172a;">
        <p>Sayın <strong>{{ $studentName }}</strong>,</p>
        <div style="white-space:pre-wrap; line-height:1.6; margin:16px 0;">{{ $body }}</div>
        <p style="margin-top:24px; color:#64748b; font-size:14px;">
          İyi çalışmalar dileriz.
        </p>
      </td>
    </tr>
    <tr>
      <td style="padding:16px 32px; background:#f8fafc; color:#94a3b8; font-size:12px; text-align:center;">
        Bu e-posta SmartRollCall sistemi üzerinden ders sorumlunuz tarafından gönderilmiştir.
      </td>
    </tr>
  </table>
</body>
</html>
