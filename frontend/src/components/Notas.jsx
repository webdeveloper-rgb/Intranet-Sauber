import { useState } from 'react';

export default function Notas({ c, onNote }) {
  const [t, setT] = useState('');
  const lista = c.notas || [];
  const add = () => {
    if (t.trim()) {
      onNote(c.id, t.trim());
      setT('');
    }
  };
  return (
    <div className="notas">
      <h3>Seguimiento ({lista.length})</h3>
      {lista.map((n, i) => (
        <div className="nota" key={i}>
          {n.texto}
          <small>{n.usuario} · {n.fecha}</small>
        </div>
      ))}
      <textarea rows="2" value={t} onChange={(e) => setT(e.target.value)}
        placeholder="Observación o nota de seguimiento…" />
      <div className="rf">
        <span />
        <button className="btn" disabled={!t.trim()} onClick={add}>Añadir nota</button>
      </div>
    </div>
  );
}
