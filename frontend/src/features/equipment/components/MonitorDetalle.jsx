import { useEffect, useState } from 'react';
import { api } from '../../../shared/api/client';
import { agregarLogoPdf } from '../../../shared/lib/pdfBranding';

function bytes(value) {
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

function tiempoEncendido(lastBootUtc, reportTimestampUtc) {
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

function enteroDisco(value) {
  if (value == null || value === '') return null;
  return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function resumenErroresDisco(disk, tipo) {
  const total = disk[`${tipo}ErrorsTotal`];
  const corregidos = disk[`${tipo}ErrorsCorrected`];
  const noCorregidos = disk[`${tipo}ErrorsUncorrected`];
  if (total == null && corregidos == null && noCorregidos == null) return null;
  return `Total ${enteroDisco(total) ?? '—'} · corregidos ${enteroDisco(corregidos) ?? '—'} · no corregidos ${enteroDisco(noCorregidos) ?? '—'}`;
}

function resumenLatenciasDisco(disk) {
  const items = [
    ['Lectura', disk.readLatencyMaxMs], ['Escritura', disk.writeLatencyMaxMs], ['Vaciado', disk.flushLatencyMaxMs],
  ].filter(([, value]) => value != null);
  return items.length ? items.map(([label, value]) => `${label}: ${enteroDisco(value)} ms`).join(' · ') : null;
}

function resumenCiclosDisco(disk, prefijo, nombre) {
  const actual = disk[`${prefijo}CycleCount`];
  const maximo = disk[`${prefijo}CycleCountMax`];
  if (actual == null && maximo == null) return null;
  return `${nombre}: ${enteroDisco(actual) ?? '—'} / ${enteroDisco(maximo) ?? '—'}`;
}

function espacioVolumen(volume) {
  const free = bytes(volume.freeBytes);
  const total = bytes(volume.sizeBytes);
  if (!free && !total) return null;
  const percent = Number(volume.sizeBytes) > 0 && Number.isFinite(Number(volume.freeBytes))
    ? ` · ${((Number(volume.freeBytes) / Number(volume.sizeBytes)) * 100).toFixed(1)}% libre`
    : '';
  return `${free || '—'} libres / ${total || '—'} capacidad${percent}`;
}

function graficasFisicas(graficas = []) {
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

function Campo({ label, value, ayuda }) {
  if (value == null || value === '') return null;
  return <div className="monitor-campo"><dt>{label}</dt><dd>{value}{ayuda && <small>{ayuda}</small>}</dd></div>;
}

function Seccion({ titulo, children, mostrar = true }) {
  if (!mostrar || !children) return null;
  return <section className="monitor-seccion"><h4>{titulo}</h4>{children}</section>;
}

function Lista({ items, render, grid = false }) {
  if (!items?.length) return null;
  return <div className={`monitor-lista${grid ? ' monitor-lista-grid' : ''}`}>{items.map((item, index) => (
    <dl className="monitor-campos" key={`${item.name || item.model || item.drive || item.slot || index}-${index}`}>
      {render(item, index)}
    </dl>
  ))}</div>;
}

function fecha(value) {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString('es-ES');
}

function tiempoDesde(value, ahora) {
  const timestamp = Date.parse(value || '');
  if (!Number.isFinite(timestamp)) return null;
  const minutos = Math.max(0, Math.floor((ahora - timestamp) / 60000));
  if (minutos < 1) return 'hace menos de un minuto';
  if (minutos < 60) return `hace ${minutos} min`;
  const horas = Math.floor(minutos / 60);
  if (horas < 24) return `hace ${horas} h`;
  const dias = Math.floor(horas / 24);
  return `hace ${dias} día${dias === 1 ? '' : 's'}`;
}

function productosSoftware(software, field) {
  if (!software || software.available === false) return 'Pendiente de actualizar el agente';
  return software[field]?.length ? software[field].join(', ') : 'No detectado';
}

function licenciaWindows(software) {
  if (!software || software.available === false) return 'Pendiente de actualizar el agente';
  if (software.windowsLicenseStatus === 'licensed') return 'Activa';
  if (software.windowsLicenseStatus === 'not_licensed') return 'No activa';
  return 'No disponible';
}

function agregarDatoPdf(rows, label, value) {
  if (value == null || value === '') return;
  rows.push([label, String(value)]);
}

function agregarListaPdf(rows, items, fields) {
  (items || []).forEach((item) => {
    fields.forEach(([label, getValue]) => {
      // The section heading already gives context; numbering every field adds noise.
      agregarDatoPdf(rows, label, getValue(item));
    });
  });
}

function agregarSeccionPdf(doc, autoTable, titulo, rows, y) {
  if (!rows.length) return y;
  if (y > doc.internal.pageSize.getHeight() - 38) {
    doc.addPage();
    y = 18;
  }
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(12);
  doc.setTextColor(38, 57, 76);
  doc.text(titulo, 14, y);
  doc.setDrawColor(220, 227, 234);
  doc.setLineWidth(0.3);
  doc.line(14, y + 2, doc.internal.pageSize.getWidth() - 14, y + 2);
  autoTable(doc, {
    startY: y + 4,
    head: [['Dato', 'Información']],
    body: rows,
    theme: 'plain',
    margin: { left: 14, right: 14, top: 18, bottom: 20 },
    styles: { font: 'helvetica', fontSize: 9.5, cellPadding: { top: 2.2, right: 2, bottom: 2.2, left: 2 }, overflow: 'linebreak', textColor: [42, 52, 62], valign: 'middle' },
    headStyles: { fillColor: [242, 246, 249], textColor: [85, 99, 113], fontStyle: 'bold', fontSize: 8, cellPadding: 2 },
    alternateRowStyles: { fillColor: [248, 250, 252] },
    columnStyles: { 0: { cellWidth: 62, fontStyle: 'normal', textColor: [85, 99, 113] }, 1: { cellWidth: 'auto' } },
    rowPageBreak: 'avoid',
  });
  return doc.lastAutoTable.finalY + 10;
}

function agregarGridPdf(doc, titulo, items, fields, y) {
  const visibles = (items || []).map((item) => fields.map(([label, getValue]) => [label, getValue(item)])
    .filter(([, value]) => value != null && value !== ''))
    .filter((rows) => rows.length);
  if (!visibles.length) return y;
  const ancho = doc.internal.pageSize.getWidth();
  const alto = doc.internal.pageSize.getHeight();
  const margen = 14;
  const gap = 5;
  const cardWidth = (ancho - margen * 2 - gap) / 2;
  const dibujarTitulo = () => {
    doc.setFont('helvetica', 'bold'); doc.setFontSize(12); doc.setTextColor(38, 57, 76);
    doc.text(titulo, margen, y);
    doc.setDrawColor(220, 227, 234); doc.setLineWidth(0.3);
    doc.line(margen, y + 2, ancho - margen, y + 2);
    y += 8;
  };
  dibujarTitulo();
  for (let i = 0; i < visibles.length; i += 2) {
    const cards = visibles.slice(i, i + 2).map((rows) => {
      let height = 8;
      const prepared = rows.map(([label, value]) => {
        doc.setFont('helvetica', 'bold'); doc.setFontSize(8);
        const labelLines = doc.splitTextToSize(label, cardWidth - 8);
        doc.setFont('helvetica', 'normal'); doc.setFontSize(9);
        const valueLines = doc.splitTextToSize(String(value), cardWidth - 8);
        const blockHeight = labelLines.length * 3.5 + valueLines.length * 4 + 3;
        height += blockHeight;
        return { labelLines, valueLines, blockHeight };
      });
      return { rows, prepared, height };
    });
    const rowHeight = Math.max(...cards.map((card) => card.height));
    if (y + rowHeight > alto - 22) { doc.addPage(); y = 18; dibujarTitulo(); }
    cards.forEach((card, column) => {
      const x = margen + column * (cardWidth + gap);
      doc.setFillColor(248, 250, 252); doc.setDrawColor(226, 232, 238);
      doc.roundedRect(x, y, cardWidth, rowHeight, 2, 2, 'FD');
      let cy = y + 5;
      card.prepared.forEach(({ labelLines, valueLines, blockHeight }) => {
        doc.setFont('helvetica', 'bold'); doc.setFontSize(8); doc.setTextColor(91, 105, 119);
        doc.text(labelLines, x + 4, cy);
        cy += labelLines.length * 3.5;
        doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(42, 52, 62);
        doc.text(valueLines, x + 4, cy);
        cy += blockHeight - labelLines.length * 3.5;
      });
    });
    y += rowHeight + 5;
  }
  return y;
}

function agregarProgramasPdf(doc, autoTable, software, y) {
  const instalados = software?.installedPrograms;
  const sinLista = !Array.isArray(instalados);
  if (sinLista && software?.available !== false) return y;
  if (y > doc.internal.pageSize.getHeight() - 38) {
    doc.addPage();
    y = 18;
  }
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(12);
  doc.setTextColor(38, 57, 76);
  doc.text(sinLista ? 'Programas instalados' : `Programas instalados (${instalados.length})`, 14, y);
  doc.setDrawColor(220, 227, 234);
  doc.setLineWidth(0.3);
  doc.line(14, y + 2, doc.internal.pageSize.getWidth() - 14, y + 2);
  const body = sinLista
    ? [['Pendiente de actualizar el agente', '', '']]
    : instalados.length
      ? instalados.map((programa) => [programa.name || '', programa.version || '—', programa.publisher || '—'])
      : [['No se detectaron programas instalados', '', '']];
  autoTable(doc, {
    startY: y + 4,
    head: [['Programa', 'Versión', 'Fabricante']],
    body,
    theme: 'plain',
    margin: { left: 14, right: 14, top: 18, bottom: 20 },
    styles: { font: 'helvetica', fontSize: 9, cellPadding: 2.2, overflow: 'linebreak', textColor: [42, 52, 62], valign: 'middle' },
    headStyles: { fillColor: [242, 246, 249], textColor: [85, 99, 113], fontStyle: 'bold', fontSize: 8, cellPadding: 2 },
    alternateRowStyles: { fillColor: [248, 250, 252] },
    columnStyles: { 0: { cellWidth: 92, fontStyle: 'normal', textColor: [65, 79, 94] }, 1: { cellWidth: 32 }, 2: { cellWidth: 'auto' } },
    rowPageBreak: 'avoid',
  });
  return doc.lastAutoTable.finalY + 10;
}

async function generarPdfEquipo({ equipo, clienteNombre, monitor, alertas, incluirProgramas }) {
  const [{ jsPDF }, { default: autoTable }] = await Promise.all([
    import('jspdf'),
    import('jspdf-autotable'),
  ]);
  const doc = new jsPDF({ unit: 'mm', format: 'a4' });
  const ancho = doc.internal.pageSize.getWidth();
  const alto = doc.internal.pageSize.getHeight();
  const generado = new Date();
  await agregarLogoPdf(doc, 14, 6, 43);
  const nombreEquipo = [equipo.nombre || `Equipo ${equipo.id}`, equipo.monitor_alias].filter(Boolean).join(' · ');
  const subtituloEquipo = [clienteNombre, equipo.tipo === 'servidor' ? 'Servidor' : equipo.tipo ? 'Equipo' : null].filter(Boolean).join('  ·  ');
  doc.setProperties({ title: `Informe técnico - ${nombreEquipo}`, subject: 'Estado y especificaciones del equipo', creator: 'Intranet-Sauber' });
  doc.setFillColor(46, 116, 151);
  doc.rect(0, 0, ancho, 3, 'F');
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  doc.setTextColor(77, 99, 117);
  doc.text('SAUBER  ·  INFORME DEL EQUIPO', 62, 14);
  doc.setFontSize(19);
  doc.setTextColor(28, 45, 61);
  const lineasNombre = doc.splitTextToSize(nombreEquipo, ancho - 28);
  doc.text(lineasNombre, 14, 31);
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(10);
  doc.setTextColor(91, 105, 119);
  doc.text(subtituloEquipo || 'Estado y especificaciones del equipo', 14, 41 + (lineasNombre.length - 1) * 6);
  doc.setFontSize(9);
  doc.setTextColor(91, 105, 119);
  doc.text(`Generado el ${fecha(generado)}`, ancho - 14, 14, { align: 'right' });

  let y = 61 + (lineasNombre.length - 1) * 6;
  const identificacion = [];
  agregarDatoPdf(identificacion, 'Cliente', clienteNombre);
  y = agregarSeccionPdf(doc, autoTable, 'Resumen del equipo', identificacion, y);

  const sistema = [];
  agregarDatoPdf(sistema, 'ID de RustDesk', monitor.rustDeskId);
  agregarDatoPdf(sistema, 'Sistema operativo', [monitor.system?.osName, monitor.system?.osVersion, monitor.system?.osArchitecture].filter(Boolean).join(' · '));
  agregarDatoPdf(sistema, 'Versión de Windows', monitor.software?.windowsRelease);
  agregarDatoPdf(sistema, 'Fabricante y modelo', [monitor.system?.manufacturer, monitor.system?.model].filter(Boolean).join(' · '));
  agregarDatoPdf(sistema, 'Número de serie', monitor.system?.serialNumber);
  agregarDatoPdf(sistema, 'Dominio', monitor.system?.domain);
  agregarDatoPdf(sistema, 'Memoria total', bytes(monitor.system?.totalRamBytes));
  agregarDatoPdf(sistema, 'Último arranque de Windows', fecha(monitor.system?.lastBootUtc));
  agregarDatoPdf(sistema, 'Tiempo encendido del PC al informe', tiempoEncendido(monitor.system?.lastBootUtc, monitor.timestampUtc));
  y = agregarSeccionPdf(doc, autoTable, 'Sistema', sistema, y);

  const software = [];
  agregarDatoPdf(software, 'Antivirus detectado', productosSoftware(monitor.software, 'antivirusProducts'));
  agregarDatoPdf(software, 'Software de copias detectado', productosSoftware(monitor.software, 'backupProducts'));
  agregarDatoPdf(software, 'Licencia de Windows', licenciaWindows(monitor.software));
  y = agregarSeccionPdf(doc, autoTable, 'Protección, copias y licencia', software, y);

  const placa = [];
  agregarDatoPdf(placa, 'Placa base', [monitor.board?.manufacturer, monitor.board?.product].filter(Boolean).join(' · '));
  agregarDatoPdf(placa, 'BIOS', [monitor.board?.biosVendor, monitor.board?.biosVersion, monitor.board?.biosDate].filter(Boolean).join(' · '));
  y = agregarSeccionPdf(doc, autoTable, 'Placa base y BIOS', placa, y);

  const procesadores = [];
  agregarListaPdf(procesadores, monitor.cpus, [
    ['Modelo', (cpu) => cpu.name],
    ['Núcleos / procesadores lógicos', (cpu) => [cpu.cores, cpu.logicalProcessors].filter((value) => value != null).join(' / ')],
    ['Frecuencia máxima', (cpu) => cpu.maxClockMHz != null ? `${cpu.maxClockMHz} MHz` : null],
  ]);
  y = agregarSeccionPdf(doc, autoTable, 'Procesadores', procesadores, y);

  y = agregarGridPdf(doc, 'Memoria RAM', monitor.ram, [
    ['Ranura', (module) => module.slot], ['Capacidad', (module) => bytes(module.capacityBytes)],
    ['Tipo / velocidad', (module) => [module.type, module.speedMHz != null ? `${module.speedMHz} MHz` : null].filter(Boolean).join(' · ')],
    ['Fabricante / referencia', (module) => [module.manufacturer, module.partNumber].filter(Boolean).join(' · ')],
  ], y);

  y = agregarGridPdf(doc, 'Gráficas', graficasFisicas(monitor.gpus), [
    ['Modelo', (gpu) => gpu.name], ['Memoria', (gpu) => bytes(gpu.adapterRamBytes)],
  ], y);

  y = agregarGridPdf(doc, 'Discos', monitor.disks, [
    ['Identificador del disco', (disk) => disk.deviceId], ['Modelo', (disk) => disk.model],
    ['Número de serie', (disk) => disk.serialNumber], ['Firmware', (disk) => disk.firmwareVersion],
    ['Salud reportada por Windows', (disk) => disk.healthStatus],
    ['Estado operativo', (disk) => disk.operationalStatus],
    ['Predicción de fallo del proveedor', (disk) => disk.predictiveFailure == null ? 'No disponible' : disk.predictiveFailure ? 'Fallo predicho' : 'Sin señal de fallo'],
    ['Desgaste utilizado reportado', (disk) => disk.wearPercent != null ? `${disk.wearPercent}%` : null],
    ['Vida estimada restante (100 − desgaste)', (disk) => disk.lifeLeftPercent != null ? `${disk.lifeLeftPercent}%` : null],
    ['Capacidad física / tipo', (disk) => [bytes(disk.sizeBytes), disk.mediaType, disk.busType].filter(Boolean).join(' · ')],
    ['Temperatura actual / máxima', (disk) => disk.temperatureC != null || disk.temperatureMaxC != null ? `${disk.temperatureC ?? '—'} / ${disk.temperatureMaxC ?? '—'} °C` : null],
    ['Uso acumulado', (disk) => disk.powerOnHours != null ? `${Number(disk.powerOnHours).toLocaleString('es-ES')} h` : null],
    ['Errores de lectura', (disk) => resumenErroresDisco(disk, 'read')],
    ['Errores de escritura', (disk) => resumenErroresDisco(disk, 'write')],
    ['Latencia máxima de E/S', resumenLatenciasDisco],
    ['Ciclos del disco', (disk) => [resumenCiclosDisco(disk, 'startStop', 'Arranque/parada'), resumenCiclosDisco(disk, 'loadUnload', 'Carga/descarga')].filter(Boolean).join(' · ') || null],
  ], y);

  const volumenes = [];
  agregarListaPdf(volumenes, monitor.volumes, [
    ['Unidad', (volume) => [volume.drive, volume.label].filter(Boolean).join(' · ')],
    ['Espacio libre / capacidad', espacioVolumen],
    ['Sistema de archivos', (volume) => volume.fileSystem],
  ]);
  y = agregarSeccionPdf(doc, autoTable, 'Volúmenes', volumenes, y);

  const baterias = [];
  agregarListaPdf(baterias, monitor.batteries, [
    ['Nombre', (battery) => battery.name],
    ['Carga', (battery) => battery.chargePercent != null ? `${battery.chargePercent}%` : null],
    ['Salud', (battery) => battery.healthPercent != null ? `${battery.healthPercent}%` : null],
    ['Capacidad de diseño / actual', (battery) => [bytes(battery.designCapacity), bytes(battery.fullChargeCapacity)].filter(Boolean).join(' / ')],
  ]);
  y = agregarSeccionPdf(doc, autoTable, 'Baterías', baterias, y);

  const alertasPdf = alertas.filter((alerta) => !(alerta.severidad === 'warning' && alerta.titulo === 'Poco espacio libre'));
  if (alertasPdf.length) {
    const filasAlertas = alertasPdf.map((alerta) => [
      `${alerta.severidad === 'critical' ? 'Crítica' : 'Advertencia'} · ${alerta.titulo || 'Alerta'}`,
      alerta.detalle || 'Sin detalle',
    ]);
    y = agregarSeccionPdf(doc, autoTable, 'Alertas activas', filasAlertas, y);
  }

  if (incluirProgramas) y = agregarProgramasPdf(doc, autoTable, monitor.software, y);

  const paginas = doc.internal.getNumberOfPages();
  for (let pagina = 1; pagina <= paginas; pagina += 1) {
    doc.setPage(pagina);
    if (pagina > 1) await agregarLogoPdf(doc, 14, 6, 30);
    doc.setDrawColor(220, 227, 234);
    doc.setLineWidth(0.25);
    doc.line(14, alto - 15, ancho - 14, alto - 15);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(105, 117, 129);
    doc.text('Intranet-Sauber  ·  Informe técnico de equipo', 14, alto - 9);
    doc.text(`Página ${pagina} de ${paginas}`, ancho - 14, alto - 8, { align: 'right' });
  }

  const nombre = String(equipo.nombre || `equipo-${equipo.id}`)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-zA-Z0-9_-]+/g, '-')
    .replace(/^-+|-+$/g, '') || `equipo-${equipo.id}`;
  doc.save(`estado-especificaciones-${nombre}-${generado.toISOString().slice(0, 10)}.pdf`);
}

// PDF solo con los programas instalados del equipo (botón independiente del informe técnico completo).
export default function MonitorDetalle({ equipo, datos, cargando, error, esAdmin = false, clienteNombre = '' }) {
  const monitor = datos?.monitor;
  const modoConfigurado = datos?.event_extraction_mode || 'standard';
  const [modoGuardado, setModoGuardado] = useState(modoConfigurado);
  const [modoEventos, setModoEventos] = useState(modoConfigurado);
  const [guardandoModo, setGuardandoModo] = useState(false);
  const [errorModo, setErrorModo] = useState('');
  const [modoActualizado, setModoActualizado] = useState(false);
  const [generandoPdf, setGenerandoPdf] = useState(false);
  const [errorPdf, setErrorPdf] = useState('');
  const [dialogoPdf, setDialogoPdf] = useState(false);
  const [incluirProgramas, setIncluirProgramas] = useState(false);
  const [horaActual, setHoraActual] = useState(() => Date.now());
  const [copiasEnCurso, setCopiasEnCurso] = useState(datos?.copias_en_curso || []);

  useEffect(() => {
    const timer = setInterval(() => setHoraActual(Date.now()), 60_000);
    return () => clearInterval(timer);
  }, []);

  useEffect(() => {
    setCopiasEnCurso(datos?.copias_en_curso || []);
    if (!monitor) return undefined;
    let activa = true;
    const actualizarActividad = () => api(`monitor_backup_activity&id=${encodeURIComponent(equipo.id)}`)
      .then((result) => { if (activa) setCopiasEnCurso(result.actividades || []); })
      .catch(() => {});
    const timer = setInterval(actualizarActividad, 20_000);
    return () => { activa = false; clearInterval(timer); };
  }, [equipo.id, !!monitor]);

  useEffect(() => {
    setModoGuardado(modoConfigurado);
    setModoEventos(modoConfigurado);
    setErrorModo('');
    setModoActualizado(false);
    setErrorPdf('');
  }, [equipo.id, modoConfigurado]);

  const guardarModoEventos = async () => {
    setGuardandoModo(true);
    setErrorModo('');
    setModoActualizado(false);
    try {
      await api('monitor_event_mode', { id: equipo.id, event_extraction_mode: modoEventos });
      setModoGuardado(modoEventos);
      setModoActualizado(true);
    } catch (e) {
      setErrorModo(e.message || 'No se pudo guardar el modo de extracción.');
    } finally {
      setGuardandoModo(false);
    }
  };

  const elegirModoEventos = (modo) => {
    if (!esAdmin || guardandoModo) return;
    setModoEventos(modo);
    setModoActualizado(false);
    setErrorModo('');
  };

  const navegarModosEventos = (event, modoActual) => {
    if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', ' ', 'Enter'].includes(event.key)) return;
    event.preventDefault();
    const modoSiguiente = [' ', 'Enter'].includes(event.key)
      ? modoActual
      : modoActual === 'standard' ? 'full' : 'standard';
    elegirModoEventos(modoSiguiente);
    document.querySelector(`[data-modo-eventos="${modoSiguiente}"]`)?.focus();
  };

  if (!monitor && cargando) return <div className="monitor-panel" role="status">Cargando especificaciones de Monitor-Equipos…</div>;
  if (!monitor && error) return <div className="errbox" role="alert">No se pudieron cargar las especificaciones: {error}</div>;
  if (!monitor) return null;

  const alerts = datos.alertas || equipo.alertas || [];
  const modoAgente = monitor.eventExtractionMode || 'standard';
  const ultimoInforme = monitor.timestampUtc || datos.monitor_last_seen;
  const minutosDesdeInforme = Number.isFinite(Date.parse(ultimoInforme || ''))
    ? Math.max(0, Math.floor((horaActual - Date.parse(ultimoInforme)) / 60000)) : null;
  const estadoAgente = minutosDesdeInforme == null
    ? { texto: 'Sin fecha de informe', clase: 'desconocido' }
    : minutosDesdeInforme <= 45
      ? { texto: 'Informe reciente', clase: 'reciente' }
      : minutosDesdeInforme <= 1440
        ? { texto: 'Informe demorado', clase: 'demorado' }
        : { texto: 'Sin informes en las últimas 24 h', clase: 'atrasado' };
  const erroresColeccion = Array.isArray(monitor.collectionErrors) ? monitor.collectionErrors : null;
  const descargarPdf = async () => {
    setErrorPdf('');
    setDialogoPdf(false);
    setGenerandoPdf(true);
    try {
      await generarPdfEquipo({ equipo, clienteNombre, monitor, alertas: alerts, incluirProgramas });
    } catch (e) {
      setErrorPdf(e.message || 'No se pudo generar el PDF.');
    } finally {
      setGenerandoPdf(false);
    }
  };
  const programasDisponibles = Array.isArray(monitor.software?.installedPrograms);
  return <section className="monitor-panel" aria-labelledby="monitor-heading">
    <div className="monitor-heading">
      <h3 id="monitor-heading">Estado y especificaciones</h3>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        <button type="button" className="btn sec" onClick={() => setDialogoPdf(true)} disabled={generandoPdf}>
          {generandoPdf ? 'Generando PDF…' : 'Descargar PDF'}
        </button>
      </div>
    </div>
    {errorPdf && <div className="errbox" role="alert">{errorPdf}</div>}
    {dialogoPdf && <div className="ov" onClick={() => setDialogoPdf(false)} />}
    {dialogoPdf && <aside className="monitor-pdf-dialog" role="dialog" aria-modal="true"
      aria-labelledby="monitor-pdf-dialog-title" aria-describedby="monitor-pdf-dialog-description">
      <header className="monitor-pdf-dialog-header">
        <span className="monitor-pdf-dialog-icon" aria-hidden="true">PDF</span>
        <div><h2 id="monitor-pdf-dialog-title">Preparar informe PDF</h2>
          <p id="monitor-pdf-dialog-description">Elige el contenido del informe de este equipo.</p></div>
        <button type="button" className="monitor-pdf-dialog-close" aria-label="Cerrar diálogo" onClick={() => setDialogoPdf(false)}>×</button>
      </header>
      <label className={`monitor-pdf-option${programasDisponibles ? '' : ' disabled'}`}>
        <input type="checkbox" checked={incluirProgramas} disabled={!programasDisponibles}
          onChange={(event) => setIncluirProgramas(event.target.checked)} />
        <span><strong>Programas instalados</strong><small>Incluye el nombre, la versión y el fabricante de cada programa.</small></span>
      </label>
      {!programasDisponibles && <p className="monitor-pdf-unavailable">El agente todavía no ha enviado la lista de programas.</p>}
      <footer className="monitor-pdf-dialog-actions">
        <button type="button" className="btn sec" onClick={() => setDialogoPdf(false)}>Cancelar</button>
        <button type="button" className="btn" onClick={descargarPdf}>Descargar PDF</button>
      </footer>
    </aside>}
    <section className={`monitor-agent-status monitor-agent-${estadoAgente.clase}`} aria-label="Estado del agente">
      <div className="monitor-agent-status-titulo">
        <span className="monitor-agent-punto" aria-hidden="true" />
        <div><strong>{estadoAgente.texto}</strong>
          <span>{ultimoInforme ? `Último informe: ${fecha(ultimoInforme)} (${tiempoDesde(ultimoInforme, horaActual)})` : 'Este equipo todavía no ha enviado un informe con fecha.'}</span>
        </div>
      </div>
      <div className="monitor-agent-version"><span>Versión del agente</span><strong>{monitor.agentVersion || 'No informada'}</strong></div>
      {erroresColeccion === null
        ? <p className="monitor-agent-errores-vacios">Este informe no incluía el detalle de errores de lectura; se mostrará al recibir un informe actualizado.</p>
        : erroresColeccion.length > 0
          ? <details className="monitor-agent-errores"><summary>Problemas al recoger datos ({erroresColeccion.length})</summary>
            <ul>{erroresColeccion.map((detalle, indice) => <li key={`${indice}-${detalle}`}>{detalle}</li>)}</ul>
          </details>
          : <span className="monitor-agent-sin-errores">Sin errores de lectura reportados</span>}
    </section>
    {copiasEnCurso.length > 0 && <section className="monitor-backup-running" aria-label="Copias de seguridad en curso" role="status">
      <div className="monitor-backup-running-heading"><span className="monitor-backup-running-dot" aria-hidden="true" />
        <div><strong>Copia de seguridad en curso</strong><span>Estado actualizado automáticamente</span></div></div>
      <ul>{copiasEnCurso.map((actividad, indice) => <li key={`${actividad.product}-${actividad.started_at}-${indice}`}>
        <strong>{actividad.product}</strong>
        <span>{Number(actividad.active_operations) > 1 ? `${actividad.active_operations} operaciones · ` : ''}Iniciada {fecha(actividad.started_at) || 'recientemente'}</span>
      </li>)}</ul>
    </section>}
    {alerts.length > 0 && <div className={`monitor-alertas ${alerts.some((alert) => alert.severidad === 'critical') ? 'critica' : 'aviso'}`}
      role={alerts.some((alert) => alert.severidad === 'critical') ? 'alert' : 'status'}>
      <h4>Alertas activas ({alerts.length})</h4>
      <ul>{alerts.map((alert) => <li key={alert.id}>
        <strong>{alert.severidad === 'critical' ? 'Crítica' : 'Advertencia'} · {alert.titulo}</strong>
        <span>{alert.detalle}</span>
      </li>)}</ul>
    </div>}

    <Seccion titulo="Sistema">
      <dl className="monitor-campos">
        <Campo label="ID de RustDesk" value={monitor.rustDeskId} />
        <Campo label="Sistema operativo" value={[monitor.system?.osName, monitor.system?.osVersion, monitor.system?.osArchitecture].filter(Boolean).join(' · ')} />
        <Campo label="Versión de Windows" value={monitor.software?.windowsRelease} />
        <Campo label="Fabricante y modelo" value={[monitor.system?.manufacturer, monitor.system?.model].filter(Boolean).join(' · ')} />
        <Campo label="Número de serie" value={monitor.system?.serialNumber} />
        <Campo label="Dominio" value={monitor.system?.domain} />
        <Campo label="Memoria total" value={bytes(monitor.system?.totalRamBytes)} />
        <Campo label="Último arranque" value={fecha(monitor.system?.lastBootUtc)} />
        <Campo label="Tiempo encendido del PC al informe" value={tiempoEncendido(monitor.system?.lastBootUtc, monitor.timestampUtc)} ayuda="Tiempo desde el último arranque hasta el informe recibido. No son las horas acumuladas del disco." />
        <Campo label="Señal SMART de Windows" value={monitor.smartPredictFailure == null ? 'No disponible' : monitor.smartPredictFailure ? 'Señal de fallo' : 'Sin señal de fallo'}
          ayuda="Lectura global de la interfaz SMART de Windows. Algunos controladores, RAID, USB y discos NVMe no la exponen; si no hay dato, no significa que el disco esté sano." />
      </dl>
    </Seccion>

    <Seccion titulo="Protección, copias y licencia">
      <dl className="monitor-campos">
        <Campo label="Antivirus detectado" value={productosSoftware(monitor.software, 'antivirusProducts')} ayuda="Combina productos registrados en el Centro de seguridad y nombres de aplicaciones conocidas." />
        <Campo label="Software de copias detectado" value={productosSoftware(monitor.software, 'backupProducts')} />
        <Campo label="Licencia de Windows" value={licenciaWindows(monitor.software)} />
      </dl>
    </Seccion>

    <Seccion titulo="Eventos del Visor de Windows">
      <div className="monitor-modo-eventos">
        <p>El modo decide qué eventos nuevos envía el agente.</p>
        <div className="monitor-modo-aplicado" aria-live="polite">
          <span>Configurado: <strong>{modoGuardado === 'full' ? 'Auditoría Completa' : 'Estándar'}</strong></span>
          <span>Aplicado por el agente: <strong>{modoAgente === 'full' ? 'Auditoría Completa' : 'Estándar'}</strong></span>
          <span className={modoAgente === modoGuardado ? 'correcto' : 'pendiente'}>
            {modoAgente === modoGuardado ? 'Sincronizado' : 'Pendiente del próximo informe'}
          </span>
        </div>
        <div className="monitor-modo-comparativa" role="radiogroup" aria-label="Modo de extracción de eventos">
          <article className={`monitor-modo-opcion${modoEventos === 'standard' ? ' seleccionado' : ''}${modoAgente === 'standard' ? ' aplicado' : ''}`}
            role="radio" aria-checked={modoEventos === 'standard'} aria-disabled={!esAdmin || guardandoModo}
            tabIndex={esAdmin && !guardandoModo && modoEventos === 'standard' ? 0 : -1}
            data-modo-eventos="standard" onClick={() => elegirModoEventos('standard')}
            onKeyDown={(event) => navegarModosEventos(event, 'standard')}>
            <h4>Estándar</h4>
            <ul>
              <li>System y Application.</li>
              <li>Error y Crítico.</li>
              <li>Menos volumen.</li>
            </ul>
          </article>
          <article className={`monitor-modo-opcion${modoEventos === 'full' ? ' seleccionado' : ''}${modoAgente === 'full' ? ' aplicado' : ''}`}
            role="radio" aria-checked={modoEventos === 'full'} aria-disabled={!esAdmin || guardandoModo}
            tabIndex={esAdmin && !guardandoModo && modoEventos === 'full' ? 0 : -1}
            data-modo-eventos="full" onClick={() => elegirModoEventos('full')}
            onKeyDown={(event) => navegarModosEventos(event, 'full')}>
            <h4>Auditoría Completa</h4>
            <ul>
              <li>Todos los canales disponibles.</li>
              <li>Crítico, Error, Advertencia, Información y Detallado.</li>
              <li>Hasta 500 eventos por informe.</li>
            </ul>
          </article>
        </div>
        {esAdmin && <button className="btn sec" disabled={guardandoModo || modoEventos === modoGuardado}
          onClick={guardarModoEventos}>
          {guardandoModo ? 'Guardando…' : 'Guardar modo'}
        </button>}
        <span className={`monitor-modo-estado${errorModo ? ' error' : ''}`} role={errorModo ? 'alert' : 'status'} aria-live="polite">
          {errorModo || (modoActualizado
            ? 'Modo guardado. El agente lo aplicará en su próximo envío.'
            : modoAgente !== modoGuardado
              ? 'El agente todavía está aplicando el modo anterior; se actualizará en su próximo envío.'
              : '')}
        </span>
      </div>
    </Seccion>

    <Seccion titulo="Placa base y BIOS">
      <dl className="monitor-campos">
        <Campo label="Placa" value={[monitor.board?.manufacturer, monitor.board?.product].filter(Boolean).join(' · ')} />
        <Campo label="BIOS" value={[monitor.board?.biosVendor, monitor.board?.biosVersion, monitor.board?.biosDate].filter(Boolean).join(' · ')} />
      </dl>
    </Seccion>

    <Seccion titulo="Procesadores" mostrar={monitor.cpus?.length >= 1}>
      <Lista items={monitor.cpus} render={(cpu) => <>
        <Campo label="Modelo" value={cpu.name} />
        <Campo label="Núcleos / procesadores lógicos" value={[cpu.cores, cpu.logicalProcessors].filter((v) => v != null).join(' / ')} />
        <Campo label="Frecuencia máxima" value={cpu.maxClockMHz != null ? `${cpu.maxClockMHz} MHz` : null} />
        <Campo label="Uso" value={cpu.loadPercent != null ? `${cpu.loadPercent}%` : null} />
      </>} />
    </Seccion>

    <Seccion titulo="Memoria RAM" mostrar={monitor.ram?.length >= 1}>
      <Lista grid items={monitor.ram} render={(module) => <>
        <Campo label="Ranura" value={module.slot} />
        <Campo label="Capacidad" value={bytes(module.capacityBytes)} />
        <Campo label="Tipo / velocidad" value={[module.type, module.speedMHz != null ? `${module.speedMHz} MHz` : null].filter(Boolean).join(' · ')} />
        <Campo label="Fabricante / referencia" value={[module.manufacturer, module.partNumber].filter(Boolean).join(' · ')} />
      </>} />
    </Seccion>

    <Seccion titulo="Gráficas" mostrar={graficasFisicas(monitor.gpus)?.length >= 1}>
      <Lista grid items={graficasFisicas(monitor.gpus)} render={(gpu) => <>
        <Campo label="Modelo" value={gpu.name} />
        <Campo label="Memoria" value={bytes(gpu.adapterRamBytes)} />
      </>} />
    </Seccion>

    <Seccion titulo="Discos" mostrar={monitor.disks?.length >= 1}>
      <Lista grid items={monitor.disks} render={(disk) => <>
        <Campo label="Identificador del disco" value={disk.deviceId} />
        <Campo label="Modelo" value={disk.model} />
        <Campo label="Número de serie" value={disk.serialNumber} />
        <Campo label="Firmware" value={disk.firmwareVersion} />
        <Campo label="Salud reportada por Windows" value={disk.healthStatus} ayuda="Estado general proporcionado por el controlador o proveedor de almacenamiento; no equivale por sí solo a un diagnóstico SMART completo." />
        <Campo label="Estado operativo" value={disk.operationalStatus} />
        <Campo label="Predicción de fallo del proveedor" value={disk.predictiveFailure == null ? 'No disponible' : disk.predictiveFailure ? 'Fallo predicho' : 'Sin señal de fallo'} />
        <Campo label="Desgaste utilizado reportado" value={disk.wearPercent != null ? `${disk.wearPercent}%` : null}
          ayuda="Porcentaje del límite estimado de desgaste informado por Windows y el dispositivo; no es una predicción de fecha de fallo." />
        <Campo label="Vida estimada restante" value={disk.lifeLeftPercent != null ? `${disk.lifeLeftPercent}%` : null}
          ayuda="Cálculo orientativo: 100% menos el desgaste reportado. Solo aparece cuando el dispositivo proporciona ese contador." />
        <Campo label="Capacidad del disco físico / tipo" value={[bytes(disk.sizeBytes), disk.mediaType, disk.busType].filter(Boolean).join(' · ')} ayuda="Capacidad del dispositivo físico; puede diferir del espacio utilizable de las particiones." />
        <Campo label="Temperatura actual / máxima" value={disk.temperatureC != null || disk.temperatureMaxC != null ? `${disk.temperatureC ?? '—'} / ${disk.temperatureMaxC ?? '—'} °C` : null} />
        <Campo label="Uso acumulado del disco" value={disk.powerOnHours != null ? `${Number(disk.powerOnHours).toLocaleString('es-ES')} h` : null} ayuda="Contador de horas de funcionamiento del disco durante toda su vida; no se reinicia al apagar el PC." />
        <Campo label="Errores de lectura" value={resumenErroresDisco(disk, 'read')} />
        <Campo label="Errores de escritura" value={resumenErroresDisco(disk, 'write')} />
        <Campo label="Latencia máxima de E/S" value={resumenLatenciasDisco(disk)} ayuda="Máximo observado por el contador de fiabilidad de Windows; puede depender del controlador y del periodo de medida." />
        <Campo label="Ciclos del disco" value={[resumenCiclosDisco(disk, 'startStop', 'Arranque/parada'), resumenCiclosDisco(disk, 'loadUnload', 'Carga/descarga')].filter(Boolean).join(' · ')} />
      </>} />
    </Seccion>

    <Seccion titulo="Volúmenes" mostrar={monitor.volumes?.length >= 1}>
      <Lista items={monitor.volumes} render={(volume) => <>
        <Campo label="Unidad" value={[volume.drive, volume.label].filter(Boolean).join(' · ')} />
        <Campo label="Espacio libre / capacidad del volumen" value={espacioVolumen(volume)} ayuda="Espacio de esta unidad lógica. Se muestra aparte de la capacidad física del disco." />
        <Campo label="Sistema de archivos" value={volume.fileSystem} />
      </>} />
    </Seccion>

    <Seccion titulo="Baterías" mostrar={monitor.batteries?.length > 0}>
      <Lista items={monitor.batteries} render={(battery) => <>
        <Campo label="Batería" value={battery.name} />
        <Campo label="Carga" value={battery.chargePercent != null ? `${battery.chargePercent}%` : null} />
        <Campo label="Salud" value={battery.healthPercent != null ? `${battery.healthPercent}%` : null} />
        <Campo label="Capacidad de diseño / actual" value={[bytes(battery.designCapacity), bytes(battery.fullChargeCapacity)].filter(Boolean).join(' / ')} />
      </>} />
    </Seccion>
  </section>;
}
