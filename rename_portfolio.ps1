# ============================
# Rename Portfolio Images Script
# ============================

$baseDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$homeDir    = Join-Path $baseDir "assets\portfolio\home"
$factoryDir = Join-Path $baseDir "assets\portfolio\factory"

# ── HOME: current order → new sequential names ──────────────────────────────
$homeMappings = @(
    @{ old = "2.1.1.jpg";       new = "1.jpg"   },
    @{ old = "2.1.2.jpg";       new = "2.jpg"   },
    @{ old = "2.1.3.jpg";       new = "3.jpg"   },
    @{ old = "2.1.jpg";         new = "4.jpg"   },
    @{ old = "2.jpg";           new = "5.jpg"   },
    @{ old = "7.jpeg";          new = "6.jpeg"  },
    @{ old = "12.jpeg";         new = "7.jpeg"  },
    @{ old = "15.png";          new = "8.png"   },
    @{ old = "18.jpeg";         new = "9.jpeg"  },
    @{ old = "19.jpeg";         new = "10.jpeg" },
    @{ old = "22.png";          new = "11.png"  },
    @{ old = "31.jpeg";         new = "12.jpeg" },
    @{ old = "32.jpeg";         new = "13.jpeg" },
    @{ old = "33.jpeg";         new = "14.jpeg" },
    @{ old = "34.jpeg";         new = "15.jpeg" },
    @{ old = "35.jpeg";         new = "16.jpeg" },
    @{ old = "42.jpg";          new = "17.jpg"  },
    @{ old = "43.jpg";          new = "18.jpg"  },
    @{ old = "44.jpg";          new = "19.jpg"  },
    @{ old = "45.jpg";          new = "20.jpg"  },
    @{ old = "46.jpg";          new = "21.jpg"  },
    @{ old = "47.jpg";          new = "22.jpg"  },
    @{ old = "48.jpg";          new = "23.jpg"  },
    @{ old = "49.jpg";          new = "24.jpg"  },
    @{ old = "5.jpeg";          new = "25.jpeg" },
    @{ old = "8.jpeg";          new = "26.jpeg" },
    @{ old = "9.jpg";           new = "27.jpg"  },
    @{ old = "S__4776134.jpg";  new = "28.jpg"  }
)

# ── FACTORY: current order → new sequential names ───────────────────────────
$factoryMappings = @(
    @{ old = "1.1.jpg";   new = "1.jpg"   },
    @{ old = "3.1.jpg";   new = "2.jpg"   },
    @{ old = "3.jpg";     new = "3.jpg"   },
    @{ old = "4.jpg";     new = "4.jpg"   },
    @{ old = "5.jpg";     new = "5.jpg"   },
    @{ old = "6.jpg";     new = "6.jpg"   },
    @{ old = "8.jpg";     new = "7.jpg"   },
    @{ old = "9.jpg";     new = "8.jpg"   },
    @{ old = "10.jpg";    new = "9.jpg"   },
    @{ old = "11.jpeg";   new = "10.jpeg" },
    @{ old = "13.jpeg";   new = "11.jpeg" },
    @{ old = "14.jpeg";   new = "12.jpeg" },
    @{ old = "16.png";    new = "13.png"  },
    @{ old = "17.jpeg";   new = "14.jpeg" },
    @{ old = "20.jpeg";   new = "15.jpeg" },
    @{ old = "21.jpeg";   new = "16.jpeg" },
    @{ old = "23.jpeg";   new = "17.jpeg" },
    @{ old = "24.png";    new = "18.png"  },
    @{ old = "25.png";    new = "19.png"  },
    @{ old = "26.png";    new = "20.png"  },
    @{ old = "27.jpeg";   new = "21.jpeg" },
    @{ old = "28.jpeg";   new = "22.jpeg" },
    @{ old = "29.jpeg";   new = "23.jpeg" },
    @{ old = "30.jpeg";   new = "24.jpeg" },
    @{ old = "36.jpeg";   new = "25.jpeg" },
    @{ old = "37.jpeg";   new = "26.jpeg" },
    @{ old = "38.jpeg";   new = "27.jpeg" },
    @{ old = "39.jpeg";   new = "28.jpeg" },
    @{ old = "40.jpg";    new = "29.jpg"  },
    @{ old = "41.jpg";    new = "30.jpg"  }
)

function Rename-Sequential {
    param($dir, $mappings)

    Write-Host "`n=== Processing: $dir ===" -ForegroundColor Cyan

    # Step 1: rename all → _tmp_N.<ext>  (avoids conflicts)
    for ($i = 0; $i -lt $mappings.Count; $i++) {
        $oldPath = Join-Path $dir $mappings[$i].old
        $ext     = [System.IO.Path]::GetExtension($mappings[$i].old)
        $tmpName = "_tmp_$i$ext"
        $tmpPath = Join-Path $dir $tmpName

        if (Test-Path $oldPath) {
            Rename-Item -Path $oldPath -NewName $tmpName
            Write-Host "  [temp] $($mappings[$i].old) → $tmpName"
        } else {
            Write-Warning "  MISSING: $($mappings[$i].old)"
        }
    }

    # Step 2: rename _tmp_N.<ext> → final name
    for ($i = 0; $i -lt $mappings.Count; $i++) {
        $ext     = [System.IO.Path]::GetExtension($mappings[$i].old)
        $tmpPath = Join-Path $dir "_tmp_$i$ext"
        $newName = $mappings[$i].new

        if (Test-Path $tmpPath) {
            Rename-Item -Path $tmpPath -NewName $newName
            Write-Host "  [done] _tmp_$i$ext → $newName" -ForegroundColor Green
        } else {
            Write-Warning "  MISSING temp: _tmp_$i$ext"
        }
    }
}

Rename-Sequential -dir $homeDir    -mappings $homeMappings
Rename-Sequential -dir $factoryDir -mappings $factoryMappings

Write-Host "`n✅ All files renamed successfully!" -ForegroundColor Green
