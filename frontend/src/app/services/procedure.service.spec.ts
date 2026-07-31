import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';

import { ProcedureService } from './procedure.service';

describe('ProcedureService', () => {
  let service: ProcedureService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    service = TestBed.inject(ProcedureService);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });
});
