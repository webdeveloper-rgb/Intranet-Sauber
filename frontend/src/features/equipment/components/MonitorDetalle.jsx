import { useEffect, useState } from 'react';
import { api } from '../../../shared/api/client';
import { bytes, tiempoEncendido, espacioVolumen, graficasFisicas, fecha, productosSoftware, licenciaWindows } from '../lib/monitorFormatters';
import { generarPdfEquipo, generarPdfProgramasEquipo } from '../lib/monitorPdf';





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
      await generarPdfEquipo({ equipo, clienteNombre, monitor, alertas: alerts });
      if (incluirProgramas) await generarPdfProgramasEquipo({ equipo, clienteNombre, monitor });
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
          <p id="monitor-pdf-dialog-description">Si incluyes los programas, se descargarán en un segundo PDF separado.</p></div>
        <button type="button" className="monitor-pdf-dialog-close" aria-label="Cerrar diálogo" onClick={() => setDialogoPdf(false)}>×</button>
      </header>
      <label className={`monitor-pdf-option${programasDisponibles ? '' : ' disabled'}`}>
        <input type="checkbox" checked={incluirProgramas} disabled={!programasDisponibles}
          onChange={(event) => setIncluirProgramas(event.target.checked)} />
        <span><strong>Programas instalados</strong><small>Descarga un segundo PDF con el nombre, la versión y el fabricante de cada programa.</small></span>
      </label>
      {!programasDisponibles && <p className="monitor-pdf-unavailable">El agente todavía no ha enviado la lista de programas.</p>}
      <footer className="monitor-pdf-dialog-actions">
        <button type="button" className="btn sec" onClick={() => setDialogoPdf(false)}>Cancelar</button>
        <button type="button" className="btn" onClick={descargarPdf}>{incluirProgramas ? 'Descargar 2 PDF' : 'Descargar PDF'}</button>
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
        <Campo label="Predicción SMART" value={monitor.smartPredictFailure == null ? 'No disponible' : monitor.smartPredictFailure ? 'Fallo previsto' : 'Sin fallo previsto'} />
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
        <Campo label="Modelo" value={disk.model} />
        <Campo label="Vida útil restante" value={disk.lifeLeftPercent != null ? `${disk.lifeLeftPercent}%` : 'No disponible'} />
        {disk.lifeLeftPercent == null && <Campo label="Estado reportado" value={disk.healthStatus} />}
        <Campo label="Capacidad del disco físico / tipo" value={[bytes(disk.sizeBytes), disk.mediaType, disk.busType].filter(Boolean).join(' · ')} ayuda="Capacidad del dispositivo físico; puede diferir del espacio utilizable de las particiones." />
        <Campo label="Temperatura" value={disk.temperatureC != null ? `${disk.temperatureC} °C` : null} />
        <Campo label="Desgaste reportado" value={disk.wearPercent != null ? `${disk.wearPercent}%` : null} />
        <Campo label="Uso acumulado del disco" value={disk.powerOnHours != null ? `${Number(disk.powerOnHours).toLocaleString('es-ES')} h` : null} ayuda="Contador de horas de funcionamiento del disco durante toda su vida; no se reinicia al apagar el PC." />
        <Campo label="Errores lectura / escritura" value={[disk.readErrorsTotal, disk.writeErrorsTotal].filter((v) => v != null).join(' / ')} />
      </>} />
    </Seccion>

    <Seccion titulo="Volúmenes" mostrar={monitor.volumes?.length >= 1}>
      <Lista grid items={monitor.volumes} render={(volume) => <>
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
