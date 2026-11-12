/**
 * Construye y descarga eventos iCalendar para las tareas de seguimiento.
 */
// Genera un evento de calendario (.ics) a las 09:00 con aviso 15 min antes
const esc = (s) => String(s).replace(/[,;]/g, (m) => '\\' + m).replace(/\n/g, '\\n');

export function crearICS(c) {
  const d = c.recordar.replace(/-/g, '');
  return [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sauber Ofimatica//Panel Copias//ES', 'BEGIN:VEVENT',
    'UID:copia-' + c.id + '-' + Date.now().toString(36) + '@sauber-panel',
    'DTSTAMP:' + new Date().toISOString().replace(/[-:]/g, '').slice(0, 15) + 'Z',
    'DTSTART:' + d + 'T090000', 'DTEND:' + d + 'T093000',
    'SUMMARY:' + esc('Revisar copia: ' + c.cliente + ' · ' + c.equipo),
    'DESCRIPTION:' + esc('Copia #' + c.id + ' (' + c.fecha + '). Error: ' + c.desc_error + '. Arreglo: ' + (c.revision_desc || '')),
    'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Recordatorio', 'TRIGGER:-PT15M', 'END:VALARM',
    'END:VEVENT', 'END:VCALENDAR',
  ].join('\r\n');
}

export function descargarICS(c) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([crearICS(c)], { type: 'text/calendar' }));
  a.download = 'recordatorio-copia-' + c.id + '.ics';
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
