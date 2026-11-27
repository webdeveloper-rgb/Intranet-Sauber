import { useCallback, useEffect, useState } from 'react';
import { api } from '../../../shared/api/client';
import Tag from '../../backups/components/Tag';

export default function HistorialMaquinaVirtual({ equipoId, equipoNombre, nombre, onClose, onSelectCopy }) {
  const [copias, setCopias] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [cargandoMas, setCargandoMas] = useState(false);
  const [hayMas, setHayMas] = useState(false);
  const [error, setError] = useState('');

  const cargar = useCallback(async (cursor = null) => {
    setError('');
    if (cursor) setCargandoMas(true);
    else setCargando(true);
    try {
      const respuesta = await api('equipo_historial', {
        equipo_id: equipoId,
        maquina_virtual: nombre,
        ...(cursor ? { cursor_fecha: cursor.cursor_fecha, cursor_id: cursor.id } : {}),
      });
      setCopias((actuales) => cursor ? [...actuales, ...respuesta.copias] : respuesta.copias);
      setHayMas(Boolean(respuesta.hay_mas_historial));
    } catch (e) {
      setError(e.message || 'No se pudo cargar el historial de esta máquina virtual.');
    } finally {
      setCargando(false);
      setCargandoMas(false);
    }
  }, [equipoId, nombre]);

  useEffect(() => {
    cargar();
  }, [cargar]);

  useEffect(() => {
    const cerrarEscape = (event) => { if (event.key === 'Escape') onClose(); };
    addEventListener('keydown', cerrarEscape);
    return () => removeEventListener('keydown', cerrarEscape);
  }, [onClose]);

  return <>
    <div className="ov ov-detalle historial-vm-overlay" onClick={onClose} />
    <aside className="detalle-modal historial-vm-modal" role="dialog" aria-modal="true" aria-labelledby="historial-vm-titulo">
      <button className="x" type="button" onClick={onClose} aria-label="Cerrar historial">Cerrar</button>
      <span className="eyebrow">Historial por máquina virtual</span>
      <h2 id="historial-vm-titulo">{nombre}</h2>
      <p className="historial-vm-equipo">Equipo: {equipoNombre}</p>
      <div className="historial-vm-contenido" aria-live="polite">
        {cargando ? <div className="mapeo-vacio">Cargando historial…</div>
          : copias.length ? <div className="historial-equipo-lista">
            {copias.map((copia) => <button type="button" className="historial-equipo-fila historial-equipo-boton" key={copia.id}
              onClick={() => onSelectCopy(copia)} aria-label={`Ver detalle de la copia del ${copia.fecha}`}>
              <span className="historial-equipo-datos">
                <time>{copia.fecha}</time>
                {copia.tarea && <span className="historial-equipo-tarea">{copia.tarea}</span>}
              </span>
              <span className="historial-equipo-etiquetas"><Tag c={copia} /></span>
              {copia.desc_error && <span className="historial-equipo-error">{copia.codigo ? `${copia.codigo} · ` : ''}{copia.desc_error}</span>}
            </button>)}
          </div> : <div className="mapeo-vacio">Todavía no hay copias registradas para esta máquina virtual.</div>}
        {error && <div className="errbox" role="alert">{error}
          <button className="btn sec" type="button" onClick={() => cargar()}>Reintentar</button>
        </div>}
        {hayMas && <button className="btn sec historial-vm-mas" type="button" disabled={cargandoMas}
          onClick={() => cargar(copias[copias.length - 1])}>
          {cargandoMas ? 'Cargando…' : 'Cargar copias anteriores'}
        </button>}
      </div>
    </aside>
  </>;
}
