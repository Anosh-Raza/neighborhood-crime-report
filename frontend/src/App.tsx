import { Route, Routes } from 'react-router-dom'
import { Layout } from './components/Layout'
import { PrompterPage } from './pages/PrompterPage'
import { ReportViewPage } from './pages/ReportViewPage'

export default function App() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<PrompterPage />} />
        <Route path="reports/:id" element={<ReportViewPage />} />
      </Route>
    </Routes>
  )
}
