import { useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { api, type Geometry } from '../api';

type Point = [number, number];
type Marker = { latitude: number; longitude: number; label: string; duplicate?: boolean };
export function PolygonMap({ value, onChange, onDrawingChange, markers = [], readOnly = false }: { value: Geometry | null; onChange: (value: Geometry | null) => void; onDrawingChange?: (drawing: boolean) => void; markers?: Marker[]; readOnly?: boolean }) {
  const element = useRef<HTMLDivElement>(null);
  const map = useRef<L.Map | null>(null);
  const layers = useRef<L.LayerGroup | null>(null);
  const pointsLayer = useRef<L.LayerGroup | null>(null);
  const [drawing, setDrawing] = useState(false);
  const [vertices, setVertices] = useState<Point[]>([]);
  const drawingRef = useRef(false);
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;
  const [search, setSearch] = useState('');
  const [searching, setSearching] = useState(false);
  const [places, setPlaces] = useState<{ label: string; latitude: number; longitude: number }[]>([]);
  const [error, setError] = useState('');

  useEffect(() => { onDrawingChange?.(drawing); }, [drawing, onDrawingChange]);

  useEffect(() => {
    if (!element.current) return;
    const m = L.map(element.current, { doubleClickZoom: false, zoomAnimation: false }).setView([46.6, 2.4], 6);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap</a>' }).addTo(m);
    layers.current = L.layerGroup().addTo(m);
    pointsLayer.current = L.layerGroup().addTo(m);
    map.current = m;
    m.on('click', e => { if (drawingRef.current) setVertices(previous => previous.length < 500 ? [...previous, [e.latlng.lng, e.latlng.lat]] : previous); });
    m.on('locationfound', e => m.setView(e.latlng, 16));
    m.on('locationerror', () => setError('Localisation indisponible. Recherchez un lieu ou déplacez la carte.'));
    const observer = new ResizeObserver(() => m.invalidateSize());
    observer.observe(element.current);
    return () => { observer.disconnect(); m.remove(); map.current = null; };
  }, []);

  useEffect(() => {
    const layer = layers.current;
    if (!layer) return;
    layer.clearLayers();
    const points = drawing ? vertices : value?.coordinates[0].slice(0, -1) ?? [];
    if (points.length > 1) {
      const positions = points.map(([lon, lat]) => L.latLng(lat, lon));
      (points.length > 2 ? L.polygon(positions, { color: '#c82d36', fillOpacity: .12 }) : L.polyline(positions, { color: '#c82d36' })).addTo(layer);
    }
    if (drawing) points.forEach(([lon, lat], index) => {
      const marker = L.marker([lat, lon], { draggable: true, icon: L.divIcon({ className: 'polygon-vertex', iconSize: [14, 14], iconAnchor: [7, 7] }) }).addTo(layer);
      marker.on('dragend', () => { const p = marker.getLatLng(); setVertices(previous => previous.map((v, i) => i === index ? [p.lng, p.lat] : v)); });
    });
  }, [value, drawing, vertices]);

  useEffect(() => {
    if (value && map.current) map.current.fitBounds(L.latLngBounds(value.coordinates[0].map(([lon, lat]) => [lat, lon] as Point)), { padding: [25, 25], maxZoom: 18 });
  }, [value]);

  useEffect(() => {
    pointsLayer.current?.clearLayers();
    markers.slice(0, 2000).forEach(p => {
      if (!Number.isFinite(p.latitude) || !Number.isFinite(p.longitude) || !pointsLayer.current) return;
      const label = document.createElement('span'); label.textContent = p.label;
      L.circleMarker([p.latitude, p.longitude], { radius: 5, color: p.duplicate ? '#64748b' : '#216747', fillOpacity: .85 }).bindTooltip(label).addTo(pointsLayer.current);
    });
  }, [markers]);

  const start = (edit = false) => {
    setVertices(edit ? value?.coordinates[0].slice(0, -1) ?? [] : []);
    setDrawing(true); drawingRef.current = true; setError('');
  };
  const finish = () => {
    if (vertices.length < 3) return;
    onChangeRef.current({ type: 'Polygon', coordinates: [[...vertices, vertices[0]]] });
    setDrawing(false); drawingRef.current = false;
  };
  const searchPlace = async () => {
    if (search.trim().length < 3) { setError('Saisissez au moins 3 caractères.'); return; }
    setSearching(true); setError(''); setPlaces([]);
    try { const result = await api<typeof places>(`/carte/recherche?q=${encodeURIComponent(search.trim())}`); setPlaces(result); if (!result.length) setError('Aucun lieu trouvé.'); }
    catch (e) { setError((e as Error).message); }
    finally { setSearching(false); }
  };

  return <div className="polygon-editor">
    <div className="map-search"><input aria-label="Rechercher un lieu" placeholder="Ville ou rue et ville…" value={search} onChange={e => setSearch(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); void searchPlace(); } }} /><button type="button" className="secondary" disabled={searching} onClick={() => void searchPlace()}>{searching ? 'Recherche…' : 'Rechercher'}</button><button type="button" className="secondary" onClick={() => map.current?.locate()}>Ma position</button></div>
    <p className="map-help">Pour trouver une rue, précisez aussi la ville, par exemple « rue Blanqui, Troyes ».</p>
    {places.length > 0 && <ul className="place-results">{places.map((p, i) => <li key={i}><button type="button" className="link" onClick={() => { map.current?.setView([p.latitude, p.longitude], 16); setPlaces([]); }}>{p.label}</button></li>)}</ul>}
    {error && <p className="error" role="alert">{error}</p>}
    <div className={`sector-map${drawing ? ' drawing' : ''}`} ref={element} role="region" aria-label="Carte OpenStreetMap du secteur" />
    {!readOnly && <><div className="map-actions">{drawing ? <><button type="button" className="primary" disabled={vertices.length < 3} onClick={finish}>Terminer le polygone</button><button type="button" className="secondary" disabled={!vertices.length} onClick={() => setVertices(p => p.slice(0, -1))}>Annuler le dernier point</button><button type="button" className="secondary" onClick={() => { setDrawing(false); drawingRef.current = false; }}>Annuler le tracé</button></> : <><button type="button" className="secondary" onClick={() => start()}>Dessiner un polygone</button>{value && <><button type="button" className="secondary" onClick={() => start(true)}>Modifier le tracé</button><button type="button" className="secondary" onClick={() => onChangeRef.current(null)}>Effacer le polygone</button></>}</>}</div>
    <p className="map-help" role="status">{drawing ? `${vertices.length} sommets · Cliquez sur la carte pour ajouter des sommets. Déplacez les points pour ajuster le tracé, puis terminez le polygone.` : value ? 'Polygone défini. Enregistrez le secteur pour conserver le tracé.' : 'Recherchez un lieu, puis dessinez les limites du secteur sur la carte.'}</p></>}
    {markers.length > 2000 && <p className="map-help">La carte affiche les 2 000 premiers points. L’import inclut toutes les adresses de l’aperçu.</p>}
  </div>;
}
