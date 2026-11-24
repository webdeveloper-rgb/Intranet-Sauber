// Reemplazo del contenido del nodo Code "Campos" del flujo de copias.
// El UID IMAP permite deduplicar el mismo correo aunque falte Message-ID.
const items = $input.all();

return items.map((item) => {
  const data = Array.isArray(item.json) ? item.json[0] : item.json;
  const subject = String(data?.subject || '');
  const rawDate = data?.date || '';
  const parsedDate = rawDate ? new Date(rawDate) : null;
  const fechaValida = parsedDate && Number.isFinite(parsedDate.getTime());
  const date = fechaValida ? parsedDate : new Date();

  const match = subject.match(/\{([^}]+)\}/);
  const cliente = match?.[1]?.trim() || 'SIN_CLIENTE';
  const headers = data?.headers || {};
  const metadata = data?.metadata || {};
  const messageId = metadata['message-id']
    || metadata['Message-ID']
    || headers['message-id']
    || headers['Message-ID']
    || data?.messageId
    || (data?.attributes?.uid != null ? `imap-uid:${data.attributes.uid}` : null)
    || [String(data?.from || ''), subject, String(rawDate || ''), String(data?.textPlain || data?.textHtml || '').replace(/\s+/g, ' ').slice(0, 160)].join('|');

  return {
    json: {
      cliente,
      descripcion: subject,
      message_id: String(messageId),
      fecha_iso: date.toISOString(),
      asunto: subject,
    },
  };
});
