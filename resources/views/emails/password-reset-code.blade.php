<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Şifre Sıfırlama Kodu</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f1f5f9; padding:20px; margin:0;">
  <table role="presentation" align="center" cellpadding="0" cellspacing="0" width="560" style="background:#ffffff; border-radius:16px; box-shadow:0 1px 3px rgba(0,0,0,0.05); overflow:hidden;">
    <tr>
      <td style="background:linear-gradient(135deg,#0ea5e9,#6366f1); padding:32px; text-align:center; color:#ffffff;">
        <h1 style="margin:0; font-size:24px;">SmartRollCall</h1>
        <p style="margin:8px 0 0; opacity:.9;">Şifre Sıfırlama</p>
      </td>
    </tr>
    <tr>
      <td style="padding:32px; color:#0f172a;">
        <p>Sayın <strong>{{ $name }}</strong>,</p>
        <p>SmartRollCall hesabınız (<code>{{ $username }}</code>) için şifre sıfırlama talebinde bulundunuz. Aşağıdaki kodu uygulamadaki sıfırlama ekranına girin:</p>

        <div style="text-align:center; margin:32px 0;">
          <div style="display:inline-block; padding:16px 32px; background:#f1f5f9; border:2px dashed #0ea5e9; border-radius:12px; font-family:'Courier New', monospace; font-size:32px; font-weight:bold; letter-spacing:8px; color:#0f172a;">
            {{ $code }}
          </div>
        </div>

        <p style="font-size:14px; color:#64748b;">
          Bu kod <strong>{{ $expiresIn }} dakika</strong> içinde geçerliliğini yitirecektir. Eğer şifre sıfırlama talebinde bulunmadıysanız bu e-postayı dikkate almayınız.
        </p>
      </td>
    </tr>
    <tr>
      <td style="padding:16px 32px; background:#f8fafc; color:#94a3b8; font-size:12px; text-align:center;">
        Bu e-posta SmartRollCall sistemi tarafından otomatik gönderilmiştir.
      </td>
    </tr>
  </table>
</body>
</html>
