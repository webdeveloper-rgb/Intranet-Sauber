import { agregarLogoPdf } from '../../../shared/lib/pdfBranding';
import { bytes, fecha, graficasFisicas, licenciaWindows, productosSoftware, espacioVolumen, tiempoEncendido } from './monitorFormatters';

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
    y = 31;
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
    margin: { left: 14, right: 14, top: 30, bottom: 20 },
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
  let tituloDibujado = false;
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
    if (!tituloDibujado) {
      // Reserva el espacio de la primera fila antes de pintar el encabezado,
      // para que nunca quede huérfano al final de la página anterior.
      if (y + 8 + rowHeight > alto - 22) { doc.addPage(); y = 31; }
      dibujarTitulo();
      tituloDibujado = true;
    } else if (y + rowHeight > alto - 22) {
      doc.addPage();
      y = 31;
      dibujarTitulo();
    }
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

function agregarGridHardwareIndividualPdf(doc, grupos, y) {
  const visibles = grupos.filter((grupo) => grupo.items?.length).map((grupo) => {
    const item = grupo.items[0];
    const filas = grupo.fields.map(([label, getValue]) => [label, getValue(item)])
      .filter(([, value]) => value != null && value !== '');
    return { titulo: grupo.titulo, filas };
  }).filter((grupo) => grupo.filas.length);
  if (visibles.length < 2) return y;

  const ancho = doc.internal.pageSize.getWidth();
  const alto = doc.internal.pageSize.getHeight();
  const margen = 14;
  const columnas = 2;
  const gap = 5;
  const cardWidth = (ancho - margen * 2 - gap) / columnas;
  const tituloSeccion = 'Componentes y almacenamiento';
  const dibujarTitulo = () => {
    doc.setFont('helvetica', 'bold'); doc.setFontSize(14); doc.setTextColor(38, 57, 76);
    doc.text(tituloSeccion, margen, y);
    doc.setDrawColor(220, 227, 234); doc.setLineWidth(0.35);
    doc.line(margen, y + 2.5, ancho - margen, y + 2.5);
    y += 9;
  };

  const tarjetas = visibles.map((grupo) => {
    doc.setFont('helvetica', 'bold'); doc.setFontSize(10.5);
    const tituloLines = doc.splitTextToSize(grupo.titulo, cardWidth - 9);
    let height = 12 + tituloLines.length * 4;
    const filas = grupo.filas.map(([label, value]) => {
      doc.setFont('helvetica', 'bold'); doc.setFontSize(8.2);
      const labelLines = doc.splitTextToSize(label, cardWidth - 9);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(9.3);
      const valueLines = doc.splitTextToSize(String(value), cardWidth - 9);
      const filaHeight = labelLines.length * 3.5 + valueLines.length * 4 + 3;
      height += filaHeight;
      return { labelLines, valueLines, filaHeight };
    });
    return { tituloLines, filas, height };
  });
  const filasTarjetas = [];
  for (let index = 0; index < tarjetas.length; index += columnas) {
    const fila = tarjetas.slice(index, index + columnas);
    filasTarjetas.push({ tarjetas: fila, height: Math.max(...fila.map((tarjeta) => tarjeta.height)) });
  }
  dibujarTitulo();

  filasTarjetas.forEach((fila) => {
    if (y + fila.height > alto - 22) {
      doc.addPage();
      y = 31;
      dibujarTitulo();
    }
    fila.tarjetas.forEach((tarjeta, indice) => {
      const x = margen + indice * (cardWidth + gap);
      doc.setFillColor(248, 250, 252); doc.setDrawColor(210, 220, 230);
      doc.setLineWidth(0.35);
      doc.roundedRect(x, y, cardWidth, tarjeta.height, 2, 2, 'FD');
      let cy = y + 6;
      doc.setFont('helvetica', 'bold'); doc.setFontSize(10.5); doc.setTextColor(38, 57, 76);
      doc.text(tarjeta.tituloLines, x + 3.5, cy);
      cy += tarjeta.tituloLines.length * 4 + 3;
      tarjeta.filas.forEach(({ labelLines, valueLines }) => {
        doc.setFont('helvetica', 'bold'); doc.setFontSize(8.2); doc.setTextColor(91, 105, 119);
        doc.text(labelLines, x + 3.5, cy);
        cy += labelLines.length * 3.5;
        doc.setFont('helvetica', 'normal'); doc.setFontSize(9.3); doc.setTextColor(42, 52, 62);
        doc.text(valueLines, x + 3.5, cy);
        cy += valueLines.length * 4 + 3;
      });
    });
    y += fila.height + gap;
  });
  return y + 3;
}

function agregarProgramasPdf(doc, autoTable, software, y) {
  const instalados = software?.installedPrograms;
  const sinLista = !Array.isArray(instalados);
  const body = sinLista
    ? [['Pendiente de actualizar el agente', '', '']]
    : instalados.length
      ? instalados.map((programa) => [programa.name || '', programa.version || '—', programa.publisher || '—'])
      : [['No se detectaron programas instalados', '', '']];
  autoTable(doc, {
    startY: y,
    head: [['Programa', 'Versión', 'Fabricante']],
    body,
    theme: 'plain',
    margin: { left: 14, right: 14, top: 30, bottom: 20 },
    styles: { font: 'helvetica', fontSize: 9, cellPadding: 2.2, overflow: 'linebreak', textColor: [42, 52, 62], valign: 'middle' },
    headStyles: { fillColor: [242, 246, 249], textColor: [85, 99, 113], fontStyle: 'bold', fontSize: 8, cellPadding: 2 },
    alternateRowStyles: { fillColor: [248, 250, 252] },
    columnStyles: { 0: { cellWidth: 92, fontStyle: 'normal', textColor: [65, 79, 94] }, 1: { cellWidth: 32 }, 2: { cellWidth: 'auto' } },
    rowPageBreak: 'avoid',
  });
}

export async function generarPdfProgramasEquipo({ equipo, clienteNombre, monitor }) {
  const [{ jsPDF }, { default: autoTable }] = await Promise.all([
    import('jspdf'),
    import('jspdf-autotable'),
  ]);
  const doc = new jsPDF({ unit: 'mm', format: 'a4' });
  const ancho = doc.internal.pageSize.getWidth();
  const alto = doc.internal.pageSize.getHeight();
  const generado = new Date();
  const anchoLogo = 86;
  const xLogo = ancho - 14 - anchoLogo;
  await agregarLogoPdf(doc, xLogo, 5, anchoLogo);

  const nombreEquipo = [equipo.nombre || `Equipo ${equipo.id}`, equipo.monitor_alias].filter(Boolean).join(' · ');
  doc.setProperties({ title: `Programas instalados - ${nombreEquipo}`, subject: 'Inventario de programas', creator: 'Intranet-Sauber' });
  doc.setFillColor(46, 116, 151);
  doc.rect(0, 0, ancho, 3, 'F');
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  doc.setTextColor(77, 99, 117);
  doc.text('SAUBER · PROGRAMAS INSTALADOS', 14, 14);
  doc.setFontSize(19);
  doc.setTextColor(28, 45, 61);
  const lineasNombre = doc.splitTextToSize(nombreEquipo, ancho - 28);
  doc.text(lineasNombre, 14, 31);
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(10);
  doc.setTextColor(91, 105, 119);
  doc.text(clienteNombre || 'Inventario de software del equipo', 14, 41 + (lineasNombre.length - 1) * 6);

  const programas = monitor.software?.installedPrograms || [];
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(12);
  doc.setTextColor(38, 57, 76);
  doc.text(`Programas instalados (${programas.length})`, 14, 57 + (lineasNombre.length - 1) * 6);
  doc.setDrawColor(220, 227, 234);
  doc.setLineWidth(0.3);
  doc.line(14, 59 + (lineasNombre.length - 1) * 6, ancho - 14, 59 + (lineasNombre.length - 1) * 6);
  agregarProgramasPdf(doc, autoTable, monitor.software, 63 + (lineasNombre.length - 1) * 6);

  const paginas = doc.internal.getNumberOfPages();
  for (let pagina = 1; pagina <= paginas; pagina += 1) {
    doc.setPage(pagina);
    if (pagina > 1) await agregarLogoPdf(doc, xLogo, 5, anchoLogo);
    doc.setDrawColor(220, 227, 234);
    doc.setLineWidth(0.25);
    doc.line(14, alto - 15, ancho - 14, alto - 15);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(105, 117, 129);
    doc.text('Intranet-Sauber · Programas instalados', 14, alto - 9);
    doc.text(`Página ${pagina} de ${paginas}`, ancho - 14, alto - 8, { align: 'right' });
  }

  const nombreSeguro = String(equipo.nombre || `equipo-${equipo.id}`)
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-zA-Z0-9_-]+/g, '-').replace(/^-+|-+$/g, '')
    || `equipo-${equipo.id}`;
  doc.save(`programas-instalados-${nombreSeguro}-${generado.toISOString().slice(0, 10)}.pdf`);
}

export async function generarPdfEquipo({ equipo, clienteNombre, monitor, alertas }) {
  const [{ jsPDF }, { default: autoTable }] = await Promise.all([
    import('jspdf'),
    import('jspdf-autotable'),
  ]);
  const doc = new jsPDF({ unit: 'mm', format: 'a4' });
  const ancho = doc.internal.pageSize.getWidth();
  const alto = doc.internal.pageSize.getHeight();
  const generado = new Date();
  const anchoLogo = 86;
  const xLogo = ancho - 14 - anchoLogo;
  await agregarLogoPdf(doc, xLogo, 5, anchoLogo);
  const nombreEquipo = [equipo.nombre || `Equipo ${equipo.id}`, equipo.monitor_alias].filter(Boolean).join(' · ');
  const subtituloEquipo = [clienteNombre, equipo.tipo === 'servidor' ? 'Servidor' : equipo.tipo ? 'Equipo' : null].filter(Boolean).join('  ·  ');
  doc.setProperties({ title: `Informe técnico - ${nombreEquipo}`, subject: 'Estado y especificaciones del equipo', creator: 'Intranet-Sauber' });
  doc.setFillColor(46, 116, 151);
  doc.rect(0, 0, ancho, 3, 'F');
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  doc.setTextColor(77, 99, 117);
  doc.text('SAUBER  ·  INFORME DEL EQUIPO', 14, 14);
  doc.setFontSize(19);
  doc.setTextColor(28, 45, 61);
  const lineasNombre = doc.splitTextToSize(nombreEquipo, ancho - 28);
  doc.text(lineasNombre, 14, 31);
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(10);
  doc.setTextColor(91, 105, 119);
  doc.text(subtituloEquipo || 'Estado y especificaciones del equipo', 14, 41 + (lineasNombre.length - 1) * 6);
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

  const graficas = graficasFisicas(monitor.gpus);
  const gruposHardwarePdf = [
    { titulo: 'Memoria RAM', items: monitor.ram, fields: [
      ['Ranura', (module) => module.slot], ['Capacidad', (module) => bytes(module.capacityBytes)],
      ['Tipo / velocidad', (module) => [module.type, module.speedMHz != null ? `${module.speedMHz} MHz` : null].filter(Boolean).join(' · ')],
      ['Fabricante / referencia', (module) => [module.manufacturer, module.partNumber].filter(Boolean).join(' · ')],
    ] },
    { titulo: 'Gráficas', items: graficas, fields: [
      ['Modelo', (gpu) => gpu.name], ['Memoria', (gpu) => bytes(gpu.adapterRamBytes)],
    ] },
    { titulo: 'Discos', items: monitor.disks, fields: [
      ['Modelo', (disk) => disk.model], ['Vida útil restante', (disk) => disk.lifeLeftPercent != null ? `${disk.lifeLeftPercent}%` : 'No disponible'],
      ['Estado reportado', (disk) => disk.lifeLeftPercent == null ? disk.healthStatus : null],
      ['Capacidad física / tipo', (disk) => [bytes(disk.sizeBytes), disk.mediaType, disk.busType].filter(Boolean).join(' · ')],
      ['Temperatura', (disk) => disk.temperatureC != null ? `${disk.temperatureC} °C` : null],
      ['Desgaste reportado', (disk) => disk.wearPercent != null ? `${disk.wearPercent}%` : null],
      ['Uso acumulado', (disk) => disk.powerOnHours != null ? `${Number(disk.powerOnHours).toLocaleString('es-ES')} h` : null],
      ['Errores lectura / escritura', (disk) => [disk.readErrorsTotal, disk.writeErrorsTotal].filter((value) => value != null).join(' / ')],
    ] },
    { titulo: 'Volúmenes', items: monitor.volumes, fields: [
      ['Unidad', (volume) => [volume.drive, volume.label].filter(Boolean).join(' · ')],
      ['Espacio libre / capacidad', espacioVolumen],
      ['Sistema de archivos', (volume) => volume.fileSystem],
    ] },
  ];
  if (gruposHardwarePdf.some((grupo) => (grupo.items || []).length > 1)
    || gruposHardwarePdf.filter((grupo) => (grupo.items || []).length > 0).length < 2) {
    gruposHardwarePdf.forEach((grupo) => {
      y = agregarGridPdf(doc, grupo.titulo, grupo.items, grupo.fields, y);
    });
  } else {
    y = agregarGridHardwareIndividualPdf(doc, gruposHardwarePdf, y);
  }

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

  const paginas = doc.internal.getNumberOfPages();
  for (let pagina = 1; pagina <= paginas; pagina += 1) {
    doc.setPage(pagina);
    if (pagina > 1) await agregarLogoPdf(doc, xLogo, 5, anchoLogo);
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
