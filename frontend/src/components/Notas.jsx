/**
 * Formulario y listado de notas de seguimiento de una copia revisada.
 */
import { useState } from 'react';
import Paginacion from './Paginacion';
import { usePagination } from '../hooks/usePagination';

export default function Notas({ c, onNote }) {
  const [t, setT] = useState('');
  const lista = c.notas || [];
  const pagina = usePagination(lista, 20, c.id);
  const add = () => {
    if (t.trim()) {
      onNote(c.id, t.trim());
      setT('');
    }
  };
  return (
    <div className="notas">
      <h3>Seguimiento ({lista.length})</h3>
      {pagina.items.map((n, i) => (
        <div className="nota" key={i}>
          {n.texto}
          <small>{n.usuario} · {n.fecha}</small>
        </div>
      ))}
      <Paginacion total={lista.length} pagina={pagina.page} paginas={pagina.pageCount}
        porPagina={pagina.pageSize} onCambiar={pagina.setPage} etiqueta="notas" />
      <textarea rows="2" value={t} onChange={(e) => setT(e.target.value)}
        placeholder="Observación o nota de seguimiento…" />
      <div className="rf">
        <span />
        <button className="btn" disabled={!t.trim()} onClick={add}>Añadir nota</button>
      </div>
    </div>
  );
}
