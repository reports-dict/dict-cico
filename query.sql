SELECT
  --m.EmployeeID,
  --m.CompanyShortName,
  CONCAT(m.LastName, ', ', m.FirstName) AS FullName,
  m.Designation,

  -- ✅ BIO TIME (Machine exists in dim_machine)
  IFNULL(
    FORMAT_DATETIME(
      '%Y-%m-%d %H:%M:%S',
      MIN(
        CASE
          WHEN dm.MachineNo IS NOT NULL
          THEN t.TK_Atl_LogDateTime
        END
      )
    ),
    ''
  ) AS BiometricTime,

  -- ✅ SAF TIME (Machine does not exist in dim_machine)
  IFNULL(
    FORMAT_DATETIME(
      '%Y-%m-%d %H:%M:%S',
      MIN(
        CASE
          WHEN dm.MachineNo IS NULL
          THEN t.TK_Atl_LogDateTime
        END
      )
    ),
    ''
  ) AS SAFTime,

  --ANY_VALUE(IFNULL(dm.Location, 'N/A')) AS MachineLocation,

  -- ✅ MACHINE NUMBER
  --ANY_VALUE(t.TK_AtL_MachNo) AS MachineNo,

  CASE
    WHEN t.TK_AtL_LogType = 0 THEN 'IN'
    WHEN t.TK_AtL_LogType = 1 THEN 'OUT'
  END AS LogType

FROM `anflo-dict-prd.biometric.bio_timelog_logistic` t

LEFT JOIN `anflo-dict-prd.biometric.bio_masterdata` m
  ON t.TK_AtL_EmpID = CAST(m.EmployeeID AS STRING)

LEFT JOIN `anflo-dict-prd.dbo.dim_machine` dm
  ON t.TK_AtL_MachNo = dm.MachineNo

WHERE
  t.TK_Atl_LogDateTime >= @start_time
  AND t.TK_Atl_LogDateTime < @end_time
  AND m.CompanyShortName = 'DICT'
  AND m.EmployeeID IS NOT NULL

  -- Exclude machine 89 but keep unmatched machines
  AND (dm.MachineNo IS NULL OR dm.MachineNo != 89)

  AND m.LastName IS NOT NULL
  AND m.FirstName IS NOT NULL
  AND UPPER(m.LastName) != 'NULL'
  AND UPPER(m.FirstName) != 'NULL'

  /*__DIRECTION_FILTER__*/

GROUP BY
  --m.EmployeeID,
  FullName,
  m.Designation,
  --dm.Direction,
  --m.CompanyShortName,
  t.TK_AtL_LogType

ORDER BY
  COALESCE(
    MIN(
      CASE
        WHEN dm.MachineNo IS NOT NULL
        THEN t.TK_Atl_LogDateTime
      END
    ),
    MIN(
      CASE
        WHEN dm.MachineNo IS NULL
        THEN t.TK_Atl_LogDateTime
      END
    )
  );