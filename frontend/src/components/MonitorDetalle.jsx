import { useEffect, useState } from 'react';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import { api } from '../api';

function bytes(value) {
  const number = Number(value);
  if (!Number.isFinite(number) || number < 0) return null;
  if (number < 1024) return `${number} B`;
  const units = ['KB', 'MB', 'GB', 'TB', 'PB'];
  let size = number;
  let unit = -1;
  do {
    size /= 1024;
    unit += 1;
  } while (size >= 1024 && unit < units.length - 1);
  return `${size.toFixed(1)} ${units[unit]}`;
}

function Campo({ label, value }) {
  if (value == null || value === '') return null;
  return <div className="monitor-campo"><dt>{label}</dt><dd>{value}</dd></div>;
}

function Seccion({ titulo, children }) {
  if (!children) return null;
  return <section className="monitor-seccion"><h4>{titulo}</h4>{children}</section>;
}

function Lista({ items, render }) {
  if (!items?.length) return null;
  return <div className="monitor-lista">{items.map((item, index) => (
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

function agregarDatoPdf(rows, label, value) {
  if (value == null || value === '') return;
  rows.push([label, String(value)]);
}

function agregarListaPdf(rows, items, prefix, fields) {
  (items || []).forEach((item, index) => {
    fields.forEach(([label, getValue]) => {
      agregarDatoPdf(rows, `${prefix} ${index + 1} · ${label}`, getValue(item));
    });
  });
}

function agregarSeccionPdf(doc, titulo, rows, y) {
  if (!rows.length) return y;
  if (y > doc.internal.pageSize.getHeight() - 28) {
    doc.addPage();
    y = 16;
  }
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(11);
  doc.setTextColor(35, 50, 70);
  doc.text(titulo, 14, y);
  autoTable(doc, {
    startY: y + 2,
    head: [['Campo', 'Valor']],
    body: rows,
    theme: 'grid',
    margin: { left: 14, right: 14, top: 14, bottom: 17 },
    styles: { font: 'helvetica', fontSize: 8, cellPadding: 2.2, overflow: 'linebreak' },
    headStyles: { fillColor: [34, 74, 112], textColor: [255, 255, 255] },
    columnStyles: { 0: { cellWidth: 48 }, 1: { cellWidth: 'auto' } },
  });
  return doc.lastAutoTable.finalY + 7;
}

function generarPdfEquipo({ equipo, clienteNombre, datos, monitor, alertas, modoConfigurado, modoAgente }) {
  const doc = new jsPDF({ unit: 'mm', format: 'a4' });
  const ancho = doc.internal.pageSize.getWidth();
  const alto = doc.internal.pageSize.getHeight();
  const generado = new Date();
  const estado = alertas.some((alerta) => alerta.severidad === 'critical')
    ? 'Crítico'
    : alertas.length ? 'Advertencias activas' : 'Sin alertas activas';
  const nombreEquipo = String(equipo.nombre || `Equipo ${equipo.id}`);
  const lineasNombre = doc.splitTextToSize(nombreEquipo, ancho - 28);

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(17);
  doc.setTextColor(27, 46, 67);
  doc.text('Estado y especificaciones', 14, 17);
  doc.setFontSize(12);
  doc.text(lineasNombre, 14, 25);
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(8);
  doc.setTextColor(90, 100, 110);
  const generadoY = 25 + lineasNombre.length * 5.5;
  doc.text(`Generado: ${fecha(generado)}`, 14, generadoY);

  let y = generadoY + 7;
  const identificacion = [];
  agregarDatoPdf(identificacion, 'Equipo', equipo.nombre);
  agregarDatoPdf(identificacion, 'ID del equipo', equipo.id);
  agregarDatoPdf(identificacion, 'Cliente', clienteNombre);
  agregarDatoPdf(identificacion, 'Tipo', equipo.tipo === 'servidor' ? 'Servidor' : equipo.tipo ? 'Equipo' : null);
  agregarDatoPdf(identificacion, 'Estado de monitorización', estado);
  agregarDatoPdf(identificacion, 'Último informe recibido', fecha(datos.monitor_last_seen || monitor.timestampUtc));
  agregarDatoPdf(identificacion, 'Modo configurado', modoConfigurado === 'full' ? 'Auditoría Completa' : 'Estándar');
  agregarDatoPdf(identificacion, 'Modo aplicado por el agente', modoAgente === 'full' ? 'Auditoría Completa' : 'Estándar');
  y = agregarSeccionPdf(doc, 'Identificación y estado', identificacion, y);

  const sistema = [];
  agregarDatoPdf(sistema, 'ID de RustDesk', monitor.rustDeskId);
  agregarDatoPdf(sistema, 'Sistema operativo', [monitor.system?.osName, monitor.system?.osVersion, monitor.system?.osArchitecture].filter(Boolean).join(' · '));
  agregarDatoPdf(sistema, 'Fabricante y modelo', [monitor.system?.manufacturer, monitor.system?.model].filter(Boolean).join(' · '));
  agregarDatoPdf(sistema, 'Número de serie', monitor.system?.serialNumber);
  agregarDatoPdf(sistema, 'Dominio', monitor.system?.domain);
  agregarDatoPdf(sistema, 'Memoria total', bytes(monitor.system?.totalRamBytes));
  agregarDatoPdf(sistema, 'Último arranque', fecha(monitor.system?.lastBootUtc));
  agregarDatoPdf(sistema, 'Predicción SMART', monitor.smartPredictFailure == null ? 'No disponible' : monitor.smartPredictFailure ? 'Fallo previsto' : 'Sin fallo previsto');
  y = agregarSeccionPdf(doc, 'Sistema', sistema, y);

  const placa = [];
  agregarDatoPdf(placa, 'Placa base', [monitor.board?.manufacturer, monitor.board?.product].filter(Boolean).join(' · '));
  agregarDatoPdf(placa, 'BIOS', [monitor.board?.biosVendor, monitor.board?.biosVersion, monitor.board?.biosDate].filter(Boolean).join(' · '));
  y = agregarSeccionPdf(doc, 'Placa base y BIOS', placa, y);

  const procesadores = [];
  agregarListaPdf(procesadores, monitor.cpus, 'Procesador', [
    ['Modelo', (cpu) => cpu.name],
    ['Núcleos / procesadores lógicos', (cpu) => [cpu.cores, cpu.logicalProcessors].filter((value) => value != null).join(' / ')],
    ['Frecuencia máxima', (cpu) => cpu.maxClockMHz != null ? `${cpu.maxClockMHz} MHz` : null],
    ['Uso', (cpu) => cpu.loadPercent != null ? `${cpu.loadPercent}%` : null],
  ]);
  y = agregarSeccionPdf(doc, 'Procesadores', procesadores, y);

  const memoria = [];
  agregarListaPdf(memoria, monitor.ram, 'Módulo', [
    ['Ranura', (module) => module.slot],
    ['Capacidad', (module) => bytes(module.capacityBytes)],
    ['Tipo / velocidad', (module) => [module.type, module.speedMHz != null ? `${module.speedMHz} MHz` : null].filter(Boolean).join(' · ')],
    ['Fabricante / referencia', (module) => [module.manufacturer, module.partNumber].filter(Boolean).join(' · ')],
  ]);
  y = agregarSeccionPdf(doc, 'Memoria RAM', memoria, y);

  const graficas = [];
  agregarListaPdf(graficas, monitor.gpus, 'Gráfica', [
    ['Modelo', (gpu) => gpu.name],
    ['Memoria', (gpu) => bytes(gpu.adapterRamBytes)],
  ]);
  y = agregarSeccionPdf(doc, 'Gráficas', graficas, y);

  const discos = [];
  agregarListaPdf(discos, monitor.disks, 'Disco', [
    ['Modelo', (disk) => disk.model],
    ['Vida útil restante', (disk) => disk.lifeLeftPercent != null ? `${disk.lifeLeftPercent}%` : 'No disponible'],
    ['Estado reportado', (disk) => disk.lifeLeftPercent == null ? disk.healthStatus : null],
    ['Capacidad / tipo', (disk) => [bytes(disk.sizeBytes), disk.mediaType, disk.busType].filter(Boolean).join(' · ')],
    ['Temperatura', (disk) => disk.temperatureC != null ? `${disk.temperatureC} °C` : null],
    ['Desgaste reportado', (disk) => disk.wearPercent != null ? `${disk.wearPercent}%` : null],
    ['Horas encendido', (disk) => disk.powerOnHours],
    ['Errores lectura / escritura', (disk) => [disk.readErrorsTotal, disk.writeErrorsTotal].filter((value) => value != null).join(' / ')],
  ]);
  y = agregarSeccionPdf(doc, 'Discos', discos, y);

  const volumenes = [];
  agregarListaPdf(volumenes, monitor.volumes, 'Volumen', [
    ['Unidad', (volume) => [volume.drive, volume.label].filter(Boolean).join(' · ')],
    ['Libre / total', (volume) => [bytes(volume.freeBytes), bytes(volume.sizeBytes)].filter(Boolean).join(' / ')],
    ['Sistema de archivos', (volume) => volume.fileSystem],
  ]);
  y = agregarSeccionPdf(doc, 'Volúmenes', volumenes, y);

  const baterias = [];
  agregarListaPdf(baterias, monitor.batteries, 'Batería', [
    ['Nombre', (battery) => battery.name],
    ['Carga', (battery) => battery.chargePercent != null ? `${battery.chargePercent}%` : null],
    ['Salud', (battery) => battery.healthPercent != null ? `${battery.healthPercent}%` : null],
    ['Capacidad de diseño / actual', (battery) => [bytes(battery.designCapacity), bytes(battery.fullChargeCapacity)].filter(Boolean).join(' / ')],
  ]);
  y = agregarSeccionPdf(doc, 'Baterías', baterias, y);

  const filasAlertas = alertas.length
    ? alertas.map((alerta) => [
      `${alerta.severidad === 'critical' ? 'Crítica' : 'Advertencia'} · ${alerta.titulo || 'Alerta'}`,
      alerta.detalle || 'Sin detalle',
    ])
    : [['Estado', 'No hay alertas activas.']];
  agregarSeccionPdf(doc, 'Alertas activas', filasAlertas, y);

  const paginas = doc.internal.getNumberOfPages();
  for (let pagina = 1; pagina <= paginas; pagina += 1) {
    doc.setPage(pagina);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    doc.setTextColor(100, 110, 120);
    doc.text('Informe generado desde Intranet-Sauber · Estado y especificaciones', 14, alto - 8);
    doc.text(`Página ${pagina} de ${paginas}`, ancho - 14, alto - 8, { align: 'right' });
  }

  const nombre = String(equipo.nombre || `equipo-${equipo.id}`)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-zA-Z0-9_-]+/g, '-')
    .replace(/^-+|-+$/g, '') || `equipo-${equipo.id}`;
  doc.save(`estado-especificaciones-${nombre}-${generado.toISOString().slice(0, 10)}.pdf`);
}

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

  if (!monitor && cargando) return <div className="monitor-panel" role="status">Cargando especificaciones de Monitor-Equipos…</div>;
  if (!monitor && error) return <div className="errbox" role="alert">No se pudieron cargar las especificaciones: {error}</div>;
  if (!monitor) return null;

  const alerts = datos.alertas || equipo.alertas || [];
  const modoAgente = monitor.eventExtractionMode || 'standard';
  const descargarPdf = () => {
    setErrorPdf('');
    setGenerandoPdf(true);
    try {
      generarPdfEquipo({
        equipo,
        clienteNombre,
        datos,
        monitor,
        alertas: alerts,
        modoConfigurado: modoGuardado,
        modoAgente,
      });
    } catch (e) {
      setErrorPdf(e.message || 'No se pudo generar el PDF.');
    } finally {
      setGenerandoPdf(false);
    }
  };
  return <section className="monitor-panel" aria-labelledby="monitor-heading">
    <div className="monitor-heading">
      <h3 id="monitor-heading">Estado y especificaciones</h3>
      <button type="button" className="btn sec" onClick={descargarPdf} disabled={generandoPdf}>
        {generandoPdf ? 'Generando PDF…' : 'Descargar PDF'}
      </button>
    </div>
    {errorPdf && <div className="errbox" role="alert">{errorPdf}</div>}
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
        <Campo label="Fabricante y modelo" value={[monitor.system?.manufacturer, monitor.system?.model].filter(Boolean).join(' · ')} />
        <Campo label="Número de serie" value={monitor.system?.serialNumber} />
        <Campo label="Dominio" value={monitor.system?.domain} />
        <Campo label="Memoria total" value={bytes(monitor.system?.totalRamBytes)} />
        <Campo label="Último arranque" value={fecha(monitor.system?.lastBootUtc)} />
        <Campo label="Predicción SMART" value={monitor.smartPredictFailure == null ? 'No disponible' : monitor.smartPredictFailure ? 'Fallo previsto' : 'Sin fallo previsto'} />
      </dl>
    </Seccion>

    <Seccion titulo="Eventos del Visor de Windows">
      <div className="monitor-modo-eventos">
        <p>Estándar envía errores y críticos de los últimos 30 días. Auditoría Completa envía todos los registros Error/Crítico retenidos en System y Application.</p>
        <label>
          Modo de extracción
          <select value={modoEventos} disabled={!esAdmin || guardandoModo}
            onChange={(event) => { setModoEventos(event.target.value); setModoActualizado(false); }}>
            <option value="standard">Estándar</option>
            <option value="full">Auditoría Completa</option>
          </select>
        </label>
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

    <Seccion titulo="Procesadores">
      <Lista items={monitor.cpus} render={(cpu) => <>
        <Campo label="Modelo" value={cpu.name} />
        <Campo label="Núcleos / procesadores lógicos" value={[cpu.cores, cpu.logicalProcessors].filter((v) => v != null).join(' / ')} />
        <Campo label="Frecuencia máxima" value={cpu.maxClockMHz != null ? `${cpu.maxClockMHz} MHz` : null} />
        <Campo label="Uso" value={cpu.loadPercent != null ? `${cpu.loadPercent}%` : null} />
      </>} />
    </Seccion>

    <Seccion titulo="Memoria RAM">
      <Lista items={monitor.ram} render={(module) => <>
        <Campo label="Ranura" value={module.slot} />
        <Campo label="Capacidad" value={bytes(module.capacityBytes)} />
        <Campo label="Tipo / velocidad" value={[module.type, module.speedMHz != null ? `${module.speedMHz} MHz` : null].filter(Boolean).join(' · ')} />
        <Campo label="Fabricante / referencia" value={[module.manufacturer, module.partNumber].filter(Boolean).join(' · ')} />
      </>} />
    </Seccion>

    <Seccion titulo="Gráficas">
      <Lista items={monitor.gpus} render={(gpu) => <>
        <Campo label="Modelo" value={gpu.name} />
        <Campo label="Memoria" value={bytes(gpu.adapterRamBytes)} />
      </>} />
    </Seccion>

    <Seccion titulo="Discos">
      <Lista items={monitor.disks} render={(disk) => <>
        <Campo label="Modelo" value={disk.model} />
        <Campo label="Vida útil restante" value={disk.lifeLeftPercent != null ? `${disk.lifeLeftPercent}%` : 'No disponible'} />
        {disk.lifeLeftPercent == null && <Campo label="Estado reportado" value={disk.healthStatus} />}
        <Campo label="Capacidad / tipo" value={[bytes(disk.sizeBytes), disk.mediaType, disk.busType].filter(Boolean).join(' · ')} />
        <Campo label="Temperatura" value={disk.temperatureC != null ? `${disk.temperatureC} °C` : null} />
        <Campo label="Desgaste reportado" value={disk.wearPercent != null ? `${disk.wearPercent}%` : null} />
        <Campo label="Horas encendido" value={disk.powerOnHours} />
        <Campo label="Errores lectura / escritura" value={[disk.readErrorsTotal, disk.writeErrorsTotal].filter((v) => v != null).join(' / ')} />
      </>} />
    </Seccion>

    <Seccion titulo="Volúmenes">
      <Lista items={monitor.volumes} render={(volume) => <>
        <Campo label="Unidad" value={[volume.drive, volume.label].filter(Boolean).join(' · ')} />
        <Campo label="Libre / total" value={[bytes(volume.freeBytes), bytes(volume.sizeBytes)].filter(Boolean).join(' / ')} />
        <Campo label="Sistema de archivos" value={volume.fileSystem} />
      </>} />
    </Seccion>

    <Seccion titulo="Baterías">
      <Lista items={monitor.batteries} render={(battery) => <>
        <Campo label="Batería" value={battery.name} />
        <Campo label="Carga" value={battery.chargePercent != null ? `${battery.chargePercent}%` : null} />
        <Campo label="Salud" value={battery.healthPercent != null ? `${battery.healthPercent}%` : null} />
        <Campo label="Capacidad de diseño / actual" value={[bytes(battery.designCapacity), bytes(battery.fullChargeCapacity)].filter(Boolean).join(' / ')} />
      </>} />
    </Seccion>
  </section>;
}
