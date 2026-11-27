import { useState } from 'react';

export default function Tooltip({ children, texto, posicion = 'top' }) {
  const [visible, setVisible] = useState(false);
  return (
    <span className="tooltip-wrap" onMouseEnter={() => setVisible(true)} onMouseLeave={() => setVisible(false)}>
      {children}
      {visible && texto ? <span className={`tooltip tooltip--${posicion}`} role="tooltip">{texto}</span> : null}
    </span>
  );
}