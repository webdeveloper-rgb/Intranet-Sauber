import { useEffect } from 'react';
import Tag from './Tag';
import Revision from './Revision';
import Seguimiento from './Seguimiento';
import Notas from './Notas';
import Historial from './Historial';

function Campo({ t, v }) {
  if (v == null || v === '') return null;
  return (<><dt>{t}</dt><dd>{v}</dd></>);
}

export default function Detalle({ c, onClose, acciones, hist, r, onOpen, modo = 'drawer', manejarEscape = true }) {
  useEffect(() => {
    if (!manejarEscape) return undefined;
    const k = (e) => e.key === 'Escape' && onClose();
    addEventListener('keydown', k);
    return () => removeEventListener('keydown', k);
  }, [onClose, manejarEscape]);

  const esError = c.estado === 'ERROR';
  return (
    <>
      <div className={`ov${modo === 'dialog' ? ' ov-detalle' : ''}`} onClick={onClose} />
      <aside className={modo === 'dialog' ? 'detalle-modal' : ''} role="dialog" aria-modal="true" aria-label="Detalle de la copia">
        <button className="x" onClick={onClose} aria-label="Cerrar">×</button>
        <h2>Copia #{c.id}</h2>
        <Tag c={c} />
        {c.desc_error && <div className="errbox"><b>{c.codigo || 'Error'}</b> · {c.desc_error}</div>}
        <dl>
          <Campo t="Fecha" v={c.fecha} />
          <Campo t="Cliente" v={c.cliente} />
          <Campo t="Equipo" v={c.equipo} />
          <Campo t="Software" v={c.software} />
          <Campo t="Destino" v={c.destino} />
          <Campo t="Tamaño" v={c.tamano} />
          <Campo t="Duración" v={c.duracion} />
          <Campo t="Reintentos" v={c.intentos} />
          <Campo t="Revisado por" v={c.revisado ? c.revisado_por : null} />
          <Campo t="Fecha revisión" v={c.revisado ? c.revisado_fecha : null} />
        </dl>
        {esError && <Revision c={c} onRevisar={acciones.revisar} onReabrir={acciones.reabrir} />}
        {esError && c.revisado && <Seguimiento c={c} onSeg={acciones.seguimiento} />}
        {esError && c.revisado && <Notas c={c} onNote={acciones.nota} />}
        <Historial c={c} hist={hist} r={r} onOpen={onOpen} />
      </aside>
    </>
  );
}
