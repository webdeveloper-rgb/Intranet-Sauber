// Nodo Code de n8n, modo Run Once for All Items, antes de clasificar con IA.
// No se inspeccionan nombres de equipos, tareas ni direcciones de correo.
return $input.all().map((item, index) => ({ ...item, pairedItem: { item: index } })).filter(({ json: item }) => {
  const fields = ['subject', 'asunto', 'textPlain', 'textHtml', 'text', 'html', 'body', 'cuerpo'];
  return !fields.some((field) => {
    if (typeof item[field] !== 'string') return false;
    const text = item[field].replace(/<[^>]*>/g, ' ')
      .replace(/&nbsp;|&#160;|&#xA0;/gi, ' ').replace(/&oacute;|&#243;|&#xF3;/gi, 'ó')
      .replace(/\s+/g, ' ');
    if (/\b(?:mensaje|correo|email|e-mail|notificaci(?:[oó]|\uFFFD|Ã³)n|env[ií]o)\s+(?:de\s+)?prueba\b|\btest\s+(?:e-?mail|message|notification)\b|\b(?:e-?mail|message|notification)\s+test\b/iu.test(text)) return true;
    return ['subject', 'asunto'].includes(field)
      && (/^\s*(?:\[(?:TEST|PRUEBA)\]|(?:TEST|PRUEBA)\s*:)|^\s*(?:TEST|PRUEBA)\s*$|(?:^|\s)[|–—-]\s*(?:TEST|PRUEBA)\s*$/iu.test(text));
  });
});
