import type { ReactNode } from 'react';
import { NavLink } from 'react-router-dom';

export default function AgendaShell({ children }: { children: ReactNode }) {
  return (
    <div className="agenda-wide">
      <nav className="tabs agenda-subtabs">
        <NavLink to="/agenda" end>Agenda</NavLink>
        <NavLink to="/agenda/guia">Guía &amp; ejemplos</NavLink>
        <NavLink to="/agenda/resumen">Resumen</NavLink>
      </nav>
      <div className="agenda-shell-content">{children}</div>
    </div>
  );
}
