<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Rack;
use App\Models\Region;
use App\Models\Site;
use App\Models\ListFasilitas;
use App\Models\ListPerangkat;

class RackController extends Controller
{
    public function indexRack()
    {
        $regions = Region::all();
        $sites = Site::all();

        // Get racks based on user role
        $racks = Rack::with(['region', 'site'])
            ->select('kode_region', 'kode_site', 'no_rack')
            ->when(auth()->user()->role != 1, function ($query) {
                return $query->where('milik', auth()->user()->id);
            })
            ->groupBy('kode_region', 'kode_site', 'no_rack')
            ->get();

        return view('menu.rack', compact('regions', 'sites', 'racks'));
    }

    public function loadRacks(Request $request)
    {
        try {
            $query = Rack::with(['region', 'site', 'listperangkat', 'listfasilitas'])
                ->select('kode_region', 'kode_site', 'no_rack')
                ->when(in_array(auth()->user()->role, [3, 4]), function ($query) {
                    return $query->whereExists(function ($subquery) {
                        $subquery->select(\DB::raw(1))
                            ->from('rack as r')
                            ->whereColumn('r.kode_region', 'rack.kode_region')
                            ->whereColumn('r.kode_site', 'rack.kode_site')
                            ->whereColumn('r.no_rack', 'rack.no_rack')
                            ->where('r.milik', auth()->user()->id);
                    });
                })
                ->groupBy('kode_region', 'kode_site', 'no_rack');

            // Apply region filter
            if ($request->has('regions') && !empty($request->regions)) {
                $query->whereIn('kode_region', $request->regions);
            }

            // Apply site filter
            if ($request->has('sites') && !empty($request->sites)) {
                $query->whereIn('kode_site', $request->sites);
            }

            // Apply search filter
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('no_rack', 'like', "%{$search}%")
                      ->orWhereHas('site', function ($q) use ($search) {
                          $q->where('nama_site', 'like', "%{$search}%");
                      })
                      ->orWhereHas('region', function ($q) use ($search) {
                          $q->where('nama_region', 'like', "%{$search}%");
                      });
                });
            }

            $racks = $query->get()
            ->map(function ($rack) {
                try {
                    $rackDetails = Rack::with(['listperangkat', 'listfasilitas'])
                        ->where('kode_region', $rack->kode_region)
                        ->where('kode_site', $rack->kode_site)
                        ->where('no_rack', $rack->no_rack)
                        ->orderBy('u', 'desc')
                        ->get();

                    $totalU = $rackDetails->count();

                    $filledU = $rackDetails->filter(function ($detail) {
                        return !is_null($detail->id_perangkat) || !is_null($detail->id_fasilitas);
                    })->count();

                    $emptyU = $rackDetails->filter(function ($detail) {
                        return is_null($detail->id_perangkat) && is_null($detail->id_fasilitas);
                    })->count();

                    $uniqueDevices = $rackDetails->filter(function ($detail) {
                        if (auth()->user()->role == 1) {
                            return !is_null($detail->id_perangkat);
                        }
                        return (string)$detail->milik === (string)auth()->user()->id && !is_null($detail->id_perangkat);
                    })->pluck('listperangkat.id_perangkat')->unique()->filter()->count();

                    $uniqueFacilities = $rackDetails->filter(function ($detail) {
                        if (auth()->user()->role == 1) {
                            return !is_null($detail->id_fasilitas);
                        }
                        return (string)$detail->milik === (string)auth()->user()->id && !is_null($detail->id_fasilitas);
                    })->pluck('listfasilitas.id_fasilitas')->unique()->filter()->count();

                    $rack->details = $rackDetails;
                    $rack->filled_u = $filledU;
                    $rack->empty_u = $emptyU;
                    $rack->device_count = $uniqueDevices;
                    $rack->facility_count = $uniqueFacilities;

                    return $rack;
                } catch (\Exception $e) {
                    \Log::error('Error processing rack details: ' . $e->getMessage());
                    return null;
                }
            })
                ->filter()
                ->values();

            // Render the HTML view
            $html = view('partials.racks', compact('racks'))->render();

            return response()->json([
                'html' => $html,
                'racks' => $racks,
                'totalRacks' => $racks->count()
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in loadRacks: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to load rack data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getSites(Request $request)
    {
        $regions = $request->get('regions', []);
        $sites = Site::whereIn('kode_region', $regions)
            ->pluck('nama_site', 'kode_site');
        return response()->json($sites);
    }

    public function storeRack(Request $request)
    {
        $validated = $request->validate([
            'kode_region' => 'required|string',
            'kode_site' => 'required|string',
            'no_rack' => 'required|string',
            'total_u' => 'required|integer|min:1',
        ]);

        for ($i = 1; $i <= $validated['total_u']; $i++) {
            Rack::create([
                'kode_region' => $validated['kode_region'],
                'kode_site' => $validated['kode_site'],
                'no_rack' => $validated['no_rack'],
                'u' => $i,
                'id_fasilitas' => null,
                'id_perangkat' => null,
            ]);
        }

        return redirect()->back()->with('success', 'Rack berhasil ditambahkan!');
    }

    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'kode_region' => 'required|string',
            'kode_site' => 'required|string',
            'no_rack' => 'required|string',
        ]);

        // Check if any U in the rack has devices or facilities
        $hasOccupiedU = Rack::where('kode_region', $validated['kode_region'])
            ->where('kode_site', $validated['kode_site'])
            ->where('no_rack', $validated['no_rack'])
            ->where(function ($query) {
                $query->whereNotNull('id_perangkat')
                    ->orWhereNotNull('id_fasilitas');
            })
            ->exists();

        if ($hasOccupiedU) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat menghapus rack karena masih ada perangkat atau fasilitas yang terpasang.'
            ]);
        }

        // Delete all U's in the rack
        $deleted = Rack::where('kode_region', $validated['kode_region'])
            ->where('kode_site', $validated['kode_site'])
            ->where('no_rack', $validated['no_rack'])
            ->delete();

        if ($deleted) {
            return response()->json([
                'success' => true,
                'message' => 'Rack berhasil dihapus'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Gagal menghapus rack'
        ]);
    }

    public function destroyData(Request $request)
    {
        $region = $request->kode_region;
        $site = $request->kode_site;
        $rack = $request->no_rack;
        $u = $request->u;

        $dataRack = Rack::where('kode_region', $region)
            ->where('kode_site', $site)
            ->where('no_rack', $rack)
            ->where('u', $u)
            ->firstOrFail();

        // Kalau ada perangkat
        if ($dataRack->id_perangkat) {
            // Kosongkan di Rack
            Rack::where('id_perangkat', $dataRack->id_perangkat)
                ->update(['id_perangkat' => null]);

            // Update individual supaya trigger updating jalan
            $perangkat = ListPerangkat::where('id_perangkat', $dataRack->id_perangkat)->first();
            if ($perangkat) {
                $perangkat->no_rack = null;
                $perangkat->uawal = null;
                $perangkat->uakhir = null;
                $perangkat->save(); // trigger updating kalau ada event
            }
        }

        // Kalau ada fasilitas
        if ($dataRack->id_fasilitas) {
            // Kosongkan di Rack
            Rack::where('id_fasilitas', $dataRack->id_fasilitas)
                ->update(['id_fasilitas' => null]);

            // Update individual supaya trigger updating jalan
            $fasilitas = ListFasilitas::where('id_fasilitas', $dataRack->id_fasilitas)->first();
            if ($fasilitas) {
                $fasilitas->no_rack = null;
                $fasilitas->uawal = null;
                $fasilitas->uakhir = null;
                $fasilitas->save(); // trigger updating yang akan simpan histori
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Perangkat/Fasilitas berhasil dihapus dari rack'
        ]);
    }

}