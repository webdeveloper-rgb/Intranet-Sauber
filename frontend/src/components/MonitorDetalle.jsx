import { useEffect, useState } from 'react';
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

export default function MonitorDetalle({ equipo, datos, cargando, error, esAdmin = false }) {
  const monitor = datos?.monitor;
  const modoConfigurado = datos?.event_extraction_mode || 'standard';
  const [modoGuardado, setModoGuardado] = useState(modoConfigurado);
  const [modoEventos, setModoEventos] = useState(modoConfigurado);
  const [guardandoModo, setGuardandoModo] = useState(false);
  const [errorModo, setErrorModo] = useState('');
  const [modoActualizado, setModoActualizado] = useState(false);

  useEffect(() => {
    setModoGuardado(modoConfigurado);
    setModoEventos(modoConfigurado);
    setErrorModo('');
    setModoActualizado(false);
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
  return <section className="monitor-panel" aria-labelledby="monitor-heading">
    <div className="monitor-heading">
      <h3 id="monitor-heading">Estado y especificaciones</h3>
    </div>
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
