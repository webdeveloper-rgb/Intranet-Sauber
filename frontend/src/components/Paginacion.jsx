export default function Paginacion({ total, pagina, paginas, porPagina = 20, onCambiar, etiqueta = 'elementos' }) {
  if (total <= porPagina) return null;
  const inicio = (pagina - 1) * porPagina + 1;
  const fin = Math.min(pagina * porPagina, total);
  return (
    <nav className="paginacion" aria-label={`Paginación de ${etiqueta}`}>
      <button className="btn sec" disabled={pagina <= 1} onClick={() => onCambiar(pagina - 1)}>Anterior</button>
      <span aria-live="polite">{inicio}–{fin} de {total} · Página {pagina} de {paginas}</span>
      <button className="btn sec" disabled={pagina >= paginas} onClick={() => onCambiar(pagina + 1)}>Siguiente</button>
    </nav>
  );
}
