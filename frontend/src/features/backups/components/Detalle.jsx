/**
 * Dialogo o panel con informacion completa de una copia y sus acciones de revision.
 */
import { useEffect } from 'react';
import Tag from './Tag';
import { etiquetaTipo } from '../../../shared/lib/logic';

function Campo({ t, v }) {
  if (v == null || v === '') return null;
  return (<><dt>{t}</dt><dd>{v}</dd></>);
}

export default function Detalle({ c, onClose, modo = 'drawer', manejarEscape = true }) {
  useEffect(() => {
    if (!manejarEscape) return undefined;
    const k = (e) => e.key === 'Escape' && onClose();
    addEventListener('keydown', k);
    return () => removeEventListener('keydown', k);
  }, [onClose, manejarEscape]);

  return (
    <>
      <div className={`ov${modo === 'dialog' ? ' ov-detalle' : ''}`} onClick={onClose} />
      <aside className={modo === 'dialog' ? 'detalle-modal' : ''} role="dialog" aria-modal="true" aria-label="Detalle de la copia">
        <button className="x" onClick={onClose} aria-label="Cerrar">Cerrar</button>
        <h2>Copia #{c.id}</h2>
        <Tag c={c} />
        {c.desc_error && <div className={`errbox${c.estado === 'ERROR' ? '' : c.estado === 'WARNING' ? ' aviso' : c.estado === 'RUNNING' ? ' en-curso' : ' revision'}`}><b>{c.codigo || (c.estado === 'ERROR' ? 'Fallo confirmado' : c.estado === 'WARNING' ? 'Advertencia' : c.estado === 'RUNNING' ? 'En curso' : 'Pendiente de clasificar')}</b> - {c.desc_error}</div>}
        <dl>
          <Campo t="Fecha" v={c.fecha} />
          <Campo t="Cliente" v={c.cliente} />
          <Campo t="Equipo" v={c.equipo} />
          <Campo t="Nombre recibido en la copia" v={c.nombre_recibido} />
          <Campo t="Tipo" v={c.tipo ? etiquetaTipo(c) : null} />
          <Campo t="Software" v={c.software} />
          <Campo t="Evidencia registrada" v={c.clasificacion_evidencia} />
          <Campo t="Destino" v={c.destino} />
          <Campo t="Tamaño" v={c.tamano} />
          <Campo t="Duración" v={c.duracion} />
          <Campo t="Reintentos" v={c.intentos} />
        </dl>
      </aside>
    </>
  );
}
