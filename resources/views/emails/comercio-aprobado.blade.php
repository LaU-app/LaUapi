<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title>Tu comercio fue verificado</title>
</head>
<body style="margin:0;padding:0;background:#f4f2f9;font-family:'Segoe UI',Helvetica,Arial,sans-serif;color:#141416;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">Tu negocio ya tiene el distintivo de verificado en LaU Marketplace.</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f2f9;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 12px 40px -18px rgba(76,29,149,.35);">
          <tr>
            <td style="background:linear-gradient(135deg,#7b32d9,#4c1d95);background-color:#6d28d9;padding:32px 32px 28px;">
              <span style="display:inline-block;background:#ffffff;border-radius:14px;padding:8px 12px;">
                <img src="{{ $logoUrl }}" alt="LaU" height="28" style="display:block;height:28px;width:auto;border:0;">
              </span>
              <p style="margin:24px 0 6px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#e9d5ff;font-weight:700;">Negocio verificado</p>
              <h1 style="margin:0;font-size:26px;line-height:32px;color:#ffffff;font-weight:800;">{{ $storeName }} ya forma parte de LaU Marketplace</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 32px 8px;">
              <p style="margin:0 0 16px;font-size:16px;line-height:24px;">Hola {{ $legalRepresentative }},</p>
              <p style="margin:0 0 16px;font-size:16px;line-height:24px;">El equipo de LaU revisó tu solicitud y <strong>aprobó tu comercio</strong>. Tu tienda ya muestra el distintivo de <strong>Verificado por LaU</strong>.</p>
              <p style="margin:0 0 24px;font-size:16px;line-height:24px;">Ya podés vender: los estudiantes apartan y pagan al recibir. Si además querés cobrar en línea, pedí PayPal desde tu panel cuando quieras; es opcional y lo aprobamos aparte.</p>
              <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
                <tr>
                  <td style="border-radius:999px;background:#141416;">
                    <a href="{{ $connectUrl }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;">Abrir mi panel</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 8px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f5fc;border-radius:16px;">
                <tr><td style="padding:18px 20px 6px;font-size:13px;font-weight:700;color:#6d28d9;text-transform:uppercase;letter-spacing:.06em;">Próximos pasos</td></tr>
                <tr><td style="padding:4px 20px;font-size:14px;line-height:22px;">1. Publicá tus productos y elegí en qué campus entregás.</td></tr>
                <tr><td style="padding:4px 20px;font-size:14px;line-height:22px;">2. Respondé a los estudiantes desde el chat de LaU.</td></tr>
                <tr><td style="padding:4px 20px 18px;font-size:14px;line-height:22px;">3. Opcional: en <strong>Pagos en línea</strong>, pedí cobrar con PayPal.</td></tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:24px 32px 32px;font-size:14px;line-height:22px;color:#55536a;">
              <p style="margin:0 0 6px;"><a href="{{ $storeUrl }}" style="color:#6d28d9;font-weight:600;">Ver mi tienda en LaU</a></p>
              @if ($replyTo)
                <p style="margin:0;">¿Dudas? Respondé este correo o escribinos a <a href="mailto:{{ $replyTo }}" style="color:#6d28d9;">{{ $replyTo }}</a>.</p>
              @endif
            </td>
          </tr>
          <tr>
            <td style="padding:18px 32px;border-top:1px solid #eeeaf6;font-size:12px;line-height:18px;color:#8f8ca3;">
              Recibiste este correo porque registraste un comercio en LaU con {{ $recipient }}.<br>
              LaU · Hecho por estudiantes en El Salvador
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
