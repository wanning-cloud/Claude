import { Link } from 'react-router';
import { PageHead } from '../components/Bits.tsx';

export default function NotFound() {
  return (
    <>
      <PageHead title="Seite nicht gefunden" lead="Diese Adresse gibt es im Cockpit nicht." />
      <Link to="/" className="btn btn-primary">
        Zur Übersicht
      </Link>
    </>
  );
}
