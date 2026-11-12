import { MapContainer, TileLayer, Circle, Marker, Popup, useMapEvents } from 'react-leaflet'
import L from 'leaflet'
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png'
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'

// Fix default marker paths under Vite
const DefaultIcon = L.icon({
  iconUrl: markerIcon,
  iconRetinaUrl: markerIcon2x,
  shadowUrl: markerShadow,
  iconSize: [25, 41],
  iconAnchor: [12, 41],
})
L.Marker.prototype.options.icon = DefaultIcon

export type MapIncident = {
  id: string
  lat?: number | null
  lng?: number | null
  crime_type?: string
  distance_km?: number | null
  summary?: string
  unclear_exact_location?: boolean
}

function DraggablePin({
  position,
  onChange,
}: {
  position: [number, number]
  onChange?: (lat: number, lng: number) => void
}) {
  useMapEvents({
    click(e) {
      onChange?.(e.latlng.lat, e.latlng.lng)
    },
  })

  return (
    <Marker
      position={position}
      draggable={Boolean(onChange)}
      eventHandlers={{
        dragend: (e) => {
          const m = e.target as L.Marker
          const p = m.getLatLng()
          onChange?.(p.lat, p.lng)
        },
      }}
    >
      <Popup>Property pin — drag to adjust</Popup>
    </Marker>
  )
}

const RINGS = [
  { km: 1, color: '#1f6b4a' },
  { km: 3, color: '#3f8f68' },
  { km: 5, color: '#c48a2a' },
  { km: 10, color: '#9b3a2f' },
]

export function ReportMap({
  center,
  incidents = [],
  radiusKm = 1,
  editable = false,
  onPinChange,
  height = 420,
}: {
  center: [number, number]
  incidents?: MapIncident[]
  radiusKm?: number
  editable?: boolean
  onPinChange?: (lat: number, lng: number) => void
  height?: number
}) {
  const visible = incidents.filter(
    (i) =>
      typeof i.lat === 'number' &&
      typeof i.lng === 'number' &&
      (i.unclear_exact_location || (i.distance_km ?? 99) <= radiusKm),
  )

  return (
    <div className="overflow-hidden rounded-2xl border border-[var(--line)]" style={{ height }}>
      <MapContainer center={center} zoom={13} style={{ height: '100%', width: '100%' }} scrollWheelZoom>
        <TileLayer
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>'
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
        />
        {RINGS.filter((r) => r.km <= Math.max(radiusKm, 1)).map((r) => (
          <Circle
            key={r.km}
            center={center}
            radius={r.km * 1000}
            pathOptions={{ color: r.color, fillOpacity: 0.04, weight: 1.5 }}
          />
        ))}
        <DraggablePin position={center} onChange={editable ? onPinChange : undefined} />
        {visible.map((i) => (
          <Marker key={i.id} position={[i.lat as number, i.lng as number]}>
            <Popup>
              <strong>{i.crime_type}</strong>
              <div>{i.distance_km != null ? `${i.distance_km} km` : 'distance unclear'}</div>
              <div>{i.summary}</div>
            </Popup>
          </Marker>
        ))}
      </MapContainer>
    </div>
  )
}
