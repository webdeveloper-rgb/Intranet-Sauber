export function bytes(value) {
  const number = Number(value);
  if (!Number.isFinite(number) || number < 0) return null;
  if (number < 1024) return `${number} B`;
  const binaryUnits = ['KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
  const decimalUnits = ['kB', 'MB', 'GB', 'TB', 'PB'];
  let binarySize = number;
  let decimalSize = number;
  let unit = -1;
  do {
    binarySize /= 1024;
    decimalSize /= 1000;
    unit += 1;
  } while (binarySize >= 1024 && unit < binaryUnits.length - 1);
  return `${binarySize.toFixed(1)} ${binaryUnits[unit]} (${decimalSize.toFixed(1)} ${decimalUnits[unit]})`;
}

export function tiempoEncendido(lastBootUtc, reportTimestampUtc) {
  if (!lastBootUtc || !reportTimestampUtc) return null;
  const lastBoot = new Date(lastBootUtc).getTime();
  const reportedAt = new Date(reportTimestampUtc).getTime();
  if (!Number.isFinite(lastBoot) || !Number.isFinite(reportedAt) || reportedAt < lastBoot) return null;
  const minutes = Math.floor((reportedAt - lastBoot) / 60000);
  const days = Math.floor(minutes / 1440);
  const hours = Math.floor((minutes % 1440) / 60);
  const remainingMinutes = minutes % 60;
  return [days ? `${days} d` : null, `${hours} h`, `${remainingMinutes} min`].filter(Boolean).join(' ');
}

export function espacioVolumen(volume) {
  const free = bytes(volume.freeBytes);
  const total = bytes(volume.sizeBytes);
  if (!free && !total) return null;
  const percent = Number(volume.sizeBytes) > 0 && Number.isFinite(Number(volume.freeBytes))
    ? ` · ${((Number(volume.freeBytes) / Number(volume.sizeBytes)) * 100).toFixed(1)}% libre`
    : '';
  return `${free || '—'} libres / ${total || '—'} capacidad${percent}`;
}

export function graficasFisicas(graficas = []) {
  const controladoresRemotos = [
    'anyviewer', 'anydesk', 'rustdesk', 'supremo', 'teamviewer', 'splashtop',
    'chrome remote desktop', 'connectwise', 'screenconnect', 'ultraviewer',
    'zoho assist', 'logmein', 'goto resolve', 'meshcentral',
  ];
  return graficas.filter((grafica) => {
    const nombre = String(grafica.name || '').toLocaleLowerCase();
    return !controladoresRemotos.some((marca) => nombre.includes(marca));
  });
}

export function fecha(value) {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString('es-ES');
}

export function productosSoftware(software, field) {
  if (!software || software.available === false) return 'Pendiente de actualizar el agente';
  return software[field]?.length ? software[field].join(', ') : 'No detectado';
}

export function licenciaWindows(software) {
  if (!software || software.available === false) return 'Pendiente de actualizar el agente';
  if (software.windowsLicenseStatus === 'licensed') return 'Activa';
  if (software.windowsLicenseStatus === 'not_licensed') return 'No activa';
  return 'No disponible';
}
