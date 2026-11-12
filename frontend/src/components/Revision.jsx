/**
 * Formulario para documentar la solucion aplicada a una copia fallida.
 */
import { useState } from 'react';
import { MIN_PALABRAS, palabras } from '../logic';

export default function Revision({ c, onRevisar, onReabrir }) {
  const [t, setT] = useState('');
  const [busy, setBusy] = useState(false);
  const n = palabras(t);
  const ok = n >= MIN_PALABRAS;

  if (c.revisado) {
    return (
      <div className="revbox">
        <b>Qué se hizo</b>
        <p>{c.revision_desc}</p>
        <div><button className="btn sec" onClick={() => onReabrir(c.id)}>Reabrir (volver a ERROR)</button></div>
      </div>
    );
  }
  const enviar = async () => {
    setBusy(true);
    await onRevisar(c.id, t.trim());
    setBusy(false);
  };
  return (
    <div className="revbox">
      <label htmlFor="rd"><b>¿Qué se ha hecho para solucionarlo?</b></label>
      <textarea id="rd" rows="3" value={t} onChange={(e) => setT(e.target.value)}
        placeholder="Ej.: Se liberó espacio en el disco de destino, se comprobó la ruta y se relanzó la copia manualmente con éxito" />
      <div className="rf">
        <span className={ok ? 'okc' : 'nok'}>{n}/{MIN_PALABRAS} palabras mínimo</span>
        <button className="btn" disabled={!ok || busy} onClick={enviar}>Marcar como revisado</button>
      </div>
    </div>
  );
}
